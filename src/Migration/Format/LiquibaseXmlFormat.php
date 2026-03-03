<?php namespace DBDiff\Migration\Format;

/**
 * Liquibase XML format.
 *
 * Produces a single `changelog.xml` file containing one <changeSet> block that
 * wraps the UP SQL in a <sql> tag and the DOWN SQL in a <rollback> tag.
 *
 * render() returns a plain string — the complete XML document.
 */
class LiquibaseXmlFormat implements FormatInterface
{
    public function render(string $up, string $down, string $description = '', string $version = ''): string
    {
        $version     = $version    ?: date('YmdHis');
        $description = $description ?: 'migration';
        $author      = 'dbdiff';

        $upSection   = $up   ? $this->indent(trim($up),   3)   : '-- (empty)';
        $downSection = $down ? $this->indent(trim($down), 5) : '-- (empty)';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<databaseChangeLog
    xmlns="http://www.liquibase.org/xml/ns/dbchangelog"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="
        http://www.liquibase.org/xml/ns/dbchangelog
        http://www.liquibase.org/xml/ns/dbchangelog/dbchangelog-4.20.xsd">

    <!--
        DBDiff-generated changelog
        Version : {$version}
        Description : {$description}
        Generated : {$this->timestamp()}
    -->

    <changeSet id="{$version}" author="{$author}" comment="{$description}">
        <sql splitStatements="true" stripComments="false"><![CDATA[
{$upSection}
        ]]></sql>

        <rollback><![CDATA[
{$downSection}
        ]]></rollback>
    </changeSet>

</databaseChangeLog>
XML;
    }

    public function getExtension(): string
    {
        return 'xml';
    }

    public function getLabel(): string
    {
        return 'Liquibase XML (changelog.xml)';
    }

    private function indent(string $sql, int $levels): string
    {
        $pad = str_repeat('    ', $levels);
        return $pad . str_replace("\n", "\n{$pad}", $sql);
    }

    private function timestamp(): string
    {
        return date('Y-m-d H:i:s');
    }
}
