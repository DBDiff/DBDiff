#!/bin/bash
# 
# DBDiff Pull Request & Issue Manager
# 
# Usage: ./scripts/manage_prs.sh [--apply] [--auto-close] [--resolve-conflicts]
#        ./scripts/manage_prs.sh --batch-close <file> [--apply]
# 
# Modes:
#   (default)              Iterate open PRs, update branches, merge if ready
#   --batch-close <file>   Close issues & PRs listed in <file> with comments
#
# Batch file format (one per line, # comments and blank lines ignored):
#   issue|<number>|<labels>|<comment>
#   pr|<number>|<labels>|<comment>
#   tag|<number>|<labels>|<comment>
#   create|<labels>|<title>|<body>
#
# Labels are comma-separated (e.g. bug,fixed) or - for none.
# 'tag' entries apply labels and add a comment but do NOT close the issue.
# 'create' entries open a new issue (useful for tracking features before closing stale PRs).
#
# Dependencies: gh (GitHub CLI), jq
#
# Dry-runs by default. Pass --apply to execute changes.

set -o pipefail

# -----------------------------------------------------------------------------
# Globals & State
# -----------------------------------------------------------------------------
DRY_RUN=true
AUTO_CLOSE=false
RESOLVE_CONFLICTS=false
BATCH_CLOSE_FILE=""
TOTAL_PRS=0
UPDATED_PRS=0
MERGED_PRS=0
SKIPPED_PRS=0
FAILED_PRS=0
CLOSED_ISSUES=0
CLOSED_PRS=0
CREATED_ISSUES=0
TAGGED_ISSUES=0
SKIPPED_CLOSES=0
FAILED_CLOSES=0
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
    if [ -n "$BATCH_CLOSE_FILE" ]; then
        printf "Issues Created:       %d\n" "$CREATED_ISSUES"
        printf "Issues Tagged:        %d\n" "$TAGGED_ISSUES"
        printf "Issues Closed:        %d\n" "$CLOSED_ISSUES"
        printf "PRs Closed:           %d\n" "$CLOSED_PRS"
        printf "Skipped (Already Done):%d\n" "$SKIPPED_CLOSES"
        printf "Failed/Errors:        %d\n" "$FAILED_CLOSES"
    else
        printf "Total PRs Found:      %d\n" "$TOTAL_PRS"
        printf "Branches Updated:     %d\n" "$UPDATED_PRS"
        printf "PRs Merged:           %d\n" "$MERGED_PRS"
        printf "Skipped (No Action):  %d\n" "$SKIPPED_PRS"
        printf "Failed/Errors:        %d\n" "$FAILED_PRS"
    fi
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
        --batch-close)
            shift
            if [[ -z "$1" || "$1" == --* ]]; then
                echo "Error: --batch-close requires a file path argument."
                exit 1
            fi
            BATCH_CLOSE_FILE="$1"
            if [[ ! -f "$BATCH_CLOSE_FILE" ]]; then
                echo "Error: batch file not found: $BATCH_CLOSE_FILE"
                exit 1
            fi
            ;;
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
# Validate batch file structure
# -----------------------------------------------------------------------------

validate_batch_file() {
    local file="$1"
    local errors=0
    local line_num=0

    while IFS= read -r line || [[ -n "$line" ]]; do
        line_num=$((line_num+1))
        [[ -z "$line" || "$line" == \#* ]] && continue

        local type="${line%%|*}"

        case "$type" in
            issue|pr|tag|create) ;;
            *)
                error_log "Line $line_num: unknown type '$type' (expected: issue, pr, create)"
                errors=$((errors+1))
                continue
                ;;
        esac

        local rest="${line#*|}"
        local field2="${rest%%|*}"
        rest="${rest#*|}"
        local field3="${rest%%|*}"
        local field4="${rest#*|}"

        if [[ "$type" == "issue" || "$type" == "pr" || "$type" == "tag" ]]; then
            if ! [[ "$field2" =~ ^[0-9]+$ ]]; then
                error_log "Line $line_num: '$field2' is not a valid issue/PR number"
                errors=$((errors+1))
            fi
            if [[ -z "$field3" ]]; then
                error_log "Line $line_num: missing labels field (use - for none)"
                errors=$((errors+1))
            fi
            if [[ -z "$field4" ]]; then
                error_log "Line $line_num: missing comment"
                errors=$((errors+1))
            fi
        else
            # create: field2=labels, field3=title, field4=body
            if [[ -z "$field3" ]]; then
                error_log "Line $line_num: missing title for create entry"
                errors=$((errors+1))
            fi
            if [[ -z "$field4" ]]; then
                error_log "Line $line_num: missing body for create entry"
                errors=$((errors+1))
            fi
        fi
    done < "$file"

    if [[ $errors -gt 0 ]]; then
        error_log "Validation failed: $errors error(s) found"
        return 1
    fi

    log "Validation passed"
    return 0
}

# -----------------------------------------------------------------------------
# Batch Close — close issues & PRs from an external file
# -----------------------------------------------------------------------------

batch_close() {
    local file="$1"

    # Validate structure before processing
    if ! validate_batch_file "$file"; then
        error_log "Aborting — fix the errors above and retry."
        exit 1
    fi

    local issue_count=0
    local pr_count=0
    local tag_count=0
    local create_count=0

    # Count entries
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ -z "$line" || "$line" == \#* ]] && continue
        local type="${line%%|*}"
        case "$type" in
            issue)  issue_count=$((issue_count+1)) ;;
            pr)     pr_count=$((pr_count+1)) ;;
            tag)    tag_count=$((tag_count+1)) ;;
            create) create_count=$((create_count+1)) ;;
        esac
    done < "$file"

    log "Batch Close — file: $file"
    log "Issues to create: $create_count"
    log "Issues to tag:    $tag_count"
    log "Issues to close:  $issue_count"
    log "PRs to close:     $pr_count"
    echo ""

    # Process entries
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ -z "$line" || "$line" == \#* ]] && continue

        local type="${line%%|*}"
        local rest="${line#*|}"
        local num="${rest%%|*}"
        rest="${rest#*|}"
        local labels="${rest%%|*}"
        local comment="${rest#*|}"

        echo "--------------------------------------------------------"

        # Apply labels to existing issues/PRs (skip 'create' — handled at creation)
        if [[ "$type" != "create" && "$type" != "tag" && "$labels" != "-" && -n "$labels" ]]; then
            local label_args=()
            IFS=',' read -ra label_list <<< "$labels"
            for lbl in "${label_list[@]}"; do
                label_args+=(--add-label "$lbl")
            done

            if [ "$DRY_RUN" = true ]; then
                echo "   [DRY-RUN] Would label #$num: $labels"
            else
                gh issue edit "$num" "${label_args[@]}" 2>/dev/null || \
                    warn "Failed to label #$num"
            fi
        fi

        case "$type" in
            tag)
                log "Tag issue #$num"

                # Apply labels
                if [[ "$labels" != "-" && -n "$labels" ]]; then
                    local tag_label_args=()
                    IFS=',' read -ra tag_label_list <<< "$labels"
                    for lbl in "${tag_label_list[@]}"; do
                        tag_label_args+=(--add-label "$lbl")
                    done

                    if [ "$DRY_RUN" = true ]; then
                        echo "   [DRY-RUN] Would label #$num: $labels"
                    else
                        gh issue edit "$num" "${tag_label_args[@]}" 2>/dev/null || \
                            warn "Failed to label #$num"
                    fi
                fi

                # Add comment (if non-empty)
                if [[ -n "$comment" ]]; then
                    if [ "$DRY_RUN" = true ]; then
                        echo "   [DRY-RUN] Would comment on #$num:"
                        echo "   $comment"
                    else
                        if gh issue comment "$num" --body "$comment" 2>/dev/null; then
                            log "Tagged issue #$num"
                            TAGGED_ISSUES=$((TAGGED_ISSUES+1))
                        else
                            error_log "Failed to comment on issue #$num"
                            FAILED_CLOSES=$((FAILED_CLOSES+1))
                        fi
                        sleep 1
                    fi
                else
                    TAGGED_ISSUES=$((TAGGED_ISSUES+1))
                fi
                ;;
            create)
                # create|<labels>|<title>|<body>
                # Re-parse: num is actually labels, labels is actually title,
                # comment is actually body
                local create_labels="$num"
                local create_title="$labels"
                local create_body="${comment//\\n/$'\n'}"

                log "Create issue: $create_title"

                if [ "$DRY_RUN" = true ]; then
                    echo "   [DRY-RUN] Would create issue:"
                    echo "   Title:  $create_title"
                    echo "   Labels: $create_labels"
                    echo "   Body:   ${create_body:0:200}..."
                else
                    # Idempotency: skip if an issue with the exact title already exists
                    local existing
                    existing=$(gh issue list --state all --search "in:title \"${create_title}\"" --json number,title -q ".[] | select(.title == \"${create_title}\") | .number" 2>/dev/null | head -1)
                    if [[ -n "$existing" ]]; then
                        log "Issue '$create_title' already exists as #$existing — skipping"
                        SKIPPED_CLOSES=$((SKIPPED_CLOSES+1))
                        continue
                    fi

                    local create_args=(--title "$create_title" --body "$create_body")
                    [[ "$create_labels" != "-" && -n "$create_labels" ]] && \
                        create_args+=(--label "$create_labels")
                    if gh issue create "${create_args[@]}" 2>/dev/null; then
                        log "Created issue: $create_title"
                        CREATED_ISSUES=$((CREATED_ISSUES+1))
                    else
                        error_log "Failed to create issue: $create_title"
                        FAILED_CLOSES=$((FAILED_CLOSES+1))
                    fi
                    sleep 1
                fi
                ;;
            issue)
                log "Issue #$num"

                if [ "$DRY_RUN" = true ]; then
                    echo "   [DRY-RUN] Would close with comment:"
                    echo "   $comment"
                else
                    # Idempotency: skip if already closed
                    local issue_state
                    issue_state=$(gh issue view "$num" --json state -q .state 2>/dev/null)
                    if [[ "$issue_state" == "CLOSED" ]]; then
                        log "Issue #$num already closed — skipping"
                        SKIPPED_CLOSES=$((SKIPPED_CLOSES+1))
                        continue
                    fi

                    if gh issue close "$num" --comment "$comment" --reason completed 2>/dev/null; then
                        log "Closed issue #$num"
                        CLOSED_ISSUES=$((CLOSED_ISSUES+1))
                    else
                        error_log "Failed to close issue #$num"
                        FAILED_CLOSES=$((FAILED_CLOSES+1))
                    fi
                    sleep 1
                fi
                ;;
            pr)
                log "PR #$num"

                if [ "$DRY_RUN" = true ]; then
                    echo "   [DRY-RUN] Would close with comment:"
                    echo "   $comment"
                else
                    # Idempotency: skip if already closed or merged
                    local pr_state
                    pr_state=$(gh pr view "$num" --json state -q .state 2>/dev/null)
                    if [[ "$pr_state" == "CLOSED" || "$pr_state" == "MERGED" ]]; then
                        log "PR #$num already ${pr_state,,} — skipping"
                        SKIPPED_CLOSES=$((SKIPPED_CLOSES+1))
                        continue
                    fi

                    if gh pr close "$num" --comment "$comment" 2>/dev/null; then
                        log "Closed PR #$num"
                        CLOSED_PRS=$((CLOSED_PRS+1))
                    else
                        error_log "Failed to close PR #$num"
                        FAILED_CLOSES=$((FAILED_CLOSES+1))
                    fi
                    sleep 1
                fi
                ;;
            *)
                warn "Unknown type '$type' on line: $line"
                ;;
        esac
    done < "$file"
}

# -----------------------------------------------------------------------------
# Mode dispatch
# -----------------------------------------------------------------------------

if [ -n "$BATCH_CLOSE_FILE" ]; then
    batch_close "$BATCH_CLOSE_FILE"
    exit 0
fi

# -----------------------------------------------------------------------------
# Main Execution — PR management (original behaviour)
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
