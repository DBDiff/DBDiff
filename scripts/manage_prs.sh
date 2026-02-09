#!/bin/bash
# 
# DBDiff Pull Request Manager
# 
# Usage: ./scripts/manage_prs.sh [--apply] [--auto-close] [--resolve-conflicts]
# 
# Dependencies: gh (GitHub CLI), jq
#
# This script is designed to be defensive and robust. It will:
# 1. Iterate through open PRs.
# 2. Attempt to update branches (handling API errors gracefully).
# 3. Check statuses (handling confusing JSON structures safely).
# 4. Merge if conditions are met.
# 5. Provide a detailed summary at the end.

set -o pipefail

# -----------------------------------------------------------------------------
# Globals & State
# -----------------------------------------------------------------------------
DRY_RUN=true
AUTO_CLOSE=false
RESOLVE_CONFLICTS=false
TOTAL_PRS=0
UPDATED_PRS=0
MERGED_PRS=0
SKIPPED_PRS=0
FAILED_PRS=0
ERRORS=()

# -----------------------------------------------------------------------------
# Helper Functions
# -----------------------------------------------------------------------------
log() {
    echo -e "[\033[0;34mINFO\033[0m] $1"
}

warn() {
    echo -e "[\033[0;33mWARN\033[0m] $1"
}

error_log() {
    echo -e "[\033[0;31mERROR\033[0m] $1"
    ERRORS+=("$1")
}

print_summary() {
    echo ""
    echo "========================================================"
    echo "               EXECUTION SUMMARY                        "
    echo "========================================================"
    printf "Total PRs Found:      %d\n" "$TOTAL_PRS"
    printf "Branches Updated:     %d\n" "$UPDATED_PRS"
    printf "PRs Merged:           %d\n" "$MERGED_PRS"
    printf "Skipped (No Action):  %d\n" "$SKIPPED_PRS"
    printf "Failed/Errors:        %d\n" "$FAILED_PRS"
    echo "========================================================"
    
    if [ ${#ERRORS[@]} -gt 0 ]; then
        echo "Errors & Warnings:"
        for err in "${ERRORS[@]}"; do
            echo " - $err"
        done
        echo "========================================================"
    fi
}

trap 'print_summary' EXIT

# -----------------------------------------------------------------------------
# Dependency Checks
# -----------------------------------------------------------------------------
if ! command -v gh >/dev/null 2>&1; then
    echo "Error: 'gh' is not installed. Please install it."
    exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "Error: 'jq' is not installed. Please install it."
    exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
    echo "Error: You must be logged in to GitHub CLI. Run 'gh auth login' first."
    exit 1
fi

# -----------------------------------------------------------------------------
# Argument Parsing
# -----------------------------------------------------------------------------
while [[ "$#" -gt 0 ]]; do
    case $1 in
        --apply) DRY_RUN=false ;;
        --auto-close) AUTO_CLOSE=true ;;
        --resolve-conflicts) RESOLVE_CONFLICTS=true ;;
        *) echo "Unknown parameter: $1"; exit 1 ;;
    esac
    shift
done

if [ "$DRY_RUN" = true ]; then
    log "RUNNING IN DRY-RUN MODE (Default). Use --apply to execute changes."
else
    warn "RUNNING IN APPLY MODE. Changes WILL be made!"
fi

# -----------------------------------------------------------------------------
# Main Execution
# -----------------------------------------------------------------------------

REPO=$(gh repo view --json nameWithOwner -q .nameWithOwner)
log "Target Repository: $REPO"

log "Fetching open PRs..."
# Fetch PRs safely
if ! PRS_JSON=$(gh pr list --state open --limit 100 --json number 2>/dev/null); then
    error_log "Failed to fetch PR list check your network or token."
    exit 1
fi

TOTAL_PRS=$(echo "$PRS_JSON" | jq '. | length')

if [ "$TOTAL_PRS" -eq 0 ]; then
    log "No open pull requests found."
    exit 0
fi

log "Found $TOTAL_PRS open PRs. Starting processing..."

for row in $(echo "${PRS_JSON}" | jq -r '.[] | @base64'); do
    _jq() {
     echo ${row} | base64 --decode | jq -r ${1}
    }
    
    number=$(_jq '.number')
    action_taken=false
    
    echo ""
    echo "--------------------------------------------------------"
    log "Processing PR #$number"
    
    # Fetch Details Safely
    if ! details=$(gh pr view $number --json title,body,statusCheckRollup,mergeable,headRefName,baseRefName,url 2>/dev/null); then
        error_log "PR #$number: Failed to fetch details. Skipping."
        FAILED_PRS=$((FAILED_PRS+1))
        continue
    fi
    
    title=$(echo "$details" | jq -r .title)
    body=$(echo "$details" | jq -r .body)
    mergeable=$(echo "$details" | jq -r .mergeable)
    headRef=$(echo "$details" | jq -r .headRefName)
    url=$(echo "$details" | jq -r .url)
    
    # Safe Status Parsing
    statuses=$(echo "$details" | jq -r '[.statusCheckRollup[]? | (.conclusion // .status // .state)] | unique | join(",")')
   
    state="UNKNOWN"
    if [[ -z "$statuses" ]]; then
       state="NONE"
    elif [[ "$statuses" == *"FAILURE"* || "$statuses" == *"TIMED_OUT"* || "$statuses" == *"ACTION_REQUIRED"* ]]; then
       state="FAILURE"
    elif [[ "$statuses" == *"IN_PROGRESS"* || "$statuses" == *"QUEUED"* || "$statuses" == *"PENDING"* ]]; then
       state="PENDING"
    elif [[ "$statuses" == "SUCCESS" || "$statuses" == "COMPLETED" || "$statuses" == "SUCCESS,COMPLETED" ]]; then
       state="SUCCESS"
    else
       state="UNKNOWN ($statuses)"
    fi

    echo "   Title:     $title"
    echo "   Branch:    $headRef"
    echo "   Mergeable: $mergeable"
    echo "   CI Status: $state ($statuses)"
    echo "   URL:       $url"

    # Step 1: Update Branch
    if [[ "$mergeable" == "CONFLICTING" ]]; then
        if [ "$RESOLVE_CONFLICTS" = false ]; then
             warn "Branch has conflicts. Cannot update or merge. (Use --resolve-conflicts to attempt auto-fix)"
        else
             log "Attempting to RESOLVE CONFLICTS..."
             
             if [ "$DRY_RUN" = true ]; then
                 log "[DRY-RUN] Would checkout PR, merge master, accept master's .github/ configs, and push."
             else
                 # Save current state
                 git stash -u >/dev/null 2>&1 || true
                 original_branch=$(git branch --show-current)
                 
                 # Checkout PR (force to overwrite local if exists)
                 if gh pr checkout $number --force >/dev/null 2>&1; then
                     pr_branch=$(git branch --show-current)
                     
                     # Attempt merge master
                     # We use origin/master to be sure
                     git fetch origin master >/dev/null 2>&1
                     
                     if git merge origin/master --no-commit --no-ff >/dev/null 2>&1; then
                         log "   Merge performed cleanly (Status check was stale?). Pushing..."
                         git push >/dev/null 2>&1
                         state="PENDING (Update Triggered)"
                     else
                         # Conflict detected
                         # Strategy: Accept Master for .github and composer.lock
                         # 'Theirs' in a PR merge (we are on PR branch, merging master) -> Master
                         
                         log "   Merge conflict detected. Applying 'Use Master' strategy for config files..."
                         
                         resolved_count=0
                         conflicted_files=$(git diff --name-only --diff-filter=U)
                         
                         for f in $conflicted_files; do
                             if [[ "$f" == ".github/"* || "$f" == "tests/"* || "$f" == "composer.lock" ]]; then
                                 # Check if file exists in master (theirs)
                                 if git cat-file -e "origin/master:$f" >/dev/null 2>&1; then
                                     log "   Resolving $f using MASTER version."
                                     git checkout --theirs "$f" >/dev/null 2>&1
                                     git add "$f"
                                     resolved_count=$((resolved_count+1))
                                 else
                                     # File apparently deleted in master?
                                     log "   File $f missing in master. Removing..."
                                     git rm "$f" >/dev/null 2>&1 || rm "$f"
                                     git add "$f"
                                     resolved_count=$((resolved_count+1))
                                 fi
                             fi
                         done
                         
                         # Check if all conflicts resolved
                         if git diff --name-only --diff-filter=U | grep -q .; then
                             warn "   Could not auto-resolve all conflicts. Aborting."
                             git merge --abort >/dev/null 2>&1
                         else
                             log "   All conflicts resolved ($resolved_count files). Committing..."
                             git commit -m "Merge branch 'master' into $pr_branch (Auto-resolved conflicts)" >/dev/null 2>&1
                             
                             if git push >/dev/null 2>&1; then
                                 log "   Successfully pushed conflict resolutions."
                                 state="PENDING (Update Triggered)"
                                 UPDATED_PRS=$((UPDATED_PRS+1))
                             else
                                 error_log "   Failed to push changes (Permission denied?)."
                             fi
                         fi
                     fi
                     
                     # Switch back
                     git checkout "$original_branch" >/dev/null 2>&1
                 else
                     error_log "   Failed to checkout PR #$number."
                 fi
                 
                 # Restore stash if any
                 git stash pop >/dev/null 2>&1 || true
             fi
        fi
    else
        # Only update if not explicitly "SUCCESS" (since update might trigger new tests)
        # OR if we just want to ensure it's up to date regardless.
        # Let's always try to update to be safe, unless it's already up to date.
        
        if [ "$DRY_RUN" = true ]; then
             log "[DRY-RUN] Would update branch."
        else
             update_out=$(gh api -X PUT "/repos/$REPO/pulls/$number/update-branch" 2>&1 || true)
             
             # Check if output is JSON or raw error
             if echo "$update_out" | grep -q "message"; then
                 msg=$(echo "$update_out" | jq -r .message 2>/dev/null || echo "$update_out")
                 if [[ "$msg" == "Accepted" ]]; then
                     log "Update triggered (Accepted)."
                     UPDATED_PRS=$((UPDATED_PRS+1))
                     action_taken=true
                 elif [[ "$msg" == *"already up to date"* ]]; then
                     echo "   Already up-to-date."
                 else
                     warn "Update failed: $msg"
                 fi
             else
                 # Fallback if no message field (unexpected API response)
                 warn "Update API response unclear: $update_out"
             fi
        fi
    fi

    # Step 2: Linked Issues & Merging
    issues=$(echo "$body" | grep -oEi "(Fixes|Closes|Resolves) #?[0-9]+" | awk '{print $2}' | tr -d '#' | tr '\n' ' ' | xargs)
    
    if [[ -n "$issues" ]]; then
        log "Linked to issue(s): $issues"
        
        if [[ "$mergeable" == "CONFLICTING" ]]; then
             # Already warned above
             :
        elif [[ "$state" == "SUCCESS" ]]; then
             if [ "$AUTO_CLOSE" = true ]; then
                 if [ "$DRY_RUN" = true ]; then
                     log "[DRY-RUN] Would MERGE PR #$number."
                     MERGED_PRS=$((MERGED_PRS+1))
                     action_taken=true
                 else
                     log "Tests Passed & Mergeable. Attempting Merge..."
                     if gh pr merge $number --merge --delete-branch >> /dev/null 2>&1; then
                         log "Successfully MERGED PR #$number."
                         MERGED_PRS=$((MERGED_PRS+1))
                         action_taken=true
                     else
                         error_log "PR #$number: Merge command failed."
                         FAILED_PRS=$((FAILED_PRS+1))
                     fi
                 fi
             else
                 log "Tests Passed. Ready to merge (Use --auto-close)."
             fi
        else
             log "Tests not passing (State: $state). Skipping merge."
        fi
    else
        echo "   No linked issues found."
    fi
    
    if [ "$action_taken" = false ]; then
        SKIPPED_PRS=$((SKIPPED_PRS+1))
    fi
    
done
