<?php

namespace App\Support;

/**
 * Builds the advisor/user import Excel template with role + firm + modules dropdowns.
 *
 * Modules dropdown lists dependency-valid multi-module packages (base Shared /
 * White Label Hub is never shown — it is always assigned on import).
 * Users may also type comma-separated module labels manually.
 */
class AdvisorImportXlsxTemplate
{
    public function __construct(
        private readonly XlsxZipBuilder $zip = new XlsxZipBuilder
    ) {}

    /**
     * @param  list<string>  $roleLabels  Display labels (renamed roles) shown in the dropdown
     * @param  list<string>  $firmNames
     * @param  list<string>  $modulePackages  Dependency-valid multi-module package labels
     */
    public function build(array $roleLabels, array $firmNames, array $modulePackages = []): string
    {
        $roleLabels = array_values(array_filter(array_map('strval', $roleLabels), fn ($v) => trim($v) !== ''));
        $firmNames = array_values(array_filter(array_map('strval', $firmNames), fn ($v) => trim($v) !== ''));
        $modulePackages = array_values(array_filter(array_map('strval', $modulePackages), fn ($v) => trim($v) !== ''));

        $sampleRole = 'User';
        foreach ($roleLabels as $label) {
            if (strcasecmp(str_replace([' ', '-'], '_', $label), 'user') === 0
                || strcasecmp($label, 'User') === 0
            ) {
                $sampleRole = $label;
                break;
            }
        }
        $sampleFirm = $firmNames[0] ?? '';
        // Prefer a multi-module package for the sample when available.
        $sampleModules = '';
        foreach ($modulePackages as $package) {
            if (str_contains($package, ',')) {
                $sampleModules = $package;
                break;
            }
        }
        if ($sampleModules === '') {
            $sampleModules = $modulePackages[0] ?? '';
        }

        $importRows = [
            ['name', 'email', 'password', 'role', 'firm', 'modules'],
            ['User', 'user@example.com', '', $sampleRole, $sampleFirm, $sampleModules],
        ];

        $listRows = [['role', 'firm', 'modules_package']];
        $max = max(count($roleLabels), count($firmNames), count($modulePackages), 1);
        for ($i = 0; $i < $max; $i++) {
            $listRows[] = [
                $roleLabels[$i] ?? '',
                $firmNames[$i] ?? '',
                $modulePackages[$i] ?? '',
            ];
        }

        $roleEnd = max(count($roleLabels), 1) + 1; // header is row 1
        $firmEnd = max(count($firmNames), 1) + 1;
        $moduleEnd = max(count($modulePackages), 1) + 1;

        $roleFormula = count($roleLabels) > 0
            ? 'Lists!$A$2:$A$'.$roleEnd
            : '"No roles configured"';
        $firmFormula = count($firmNames) > 0
            ? 'Lists!$B$2:$B$'.$firmEnd
            : '"No firms configured"';
        $moduleFormula = count($modulePackages) > 0
            ? 'Lists!$C$2:$C$'.$moduleEnd
            : '"No modules enabled"';

        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => $this->rels(),
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml' => $this->styles(),
            'xl/worksheets/sheet1.xml' => $this->importSheet($importRows, $roleFormula, $firmFormula, $moduleFormula),
            'xl/worksheets/sheet2.xml' => $this->listsSheet($listRows),
        ];

        return $this->zip->build($files);
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'
            .'<sheet name="Import" sheetId="1" r:id="rId1"/>'
            .'<sheet name="Lists" sheetId="2" state="hidden" r:id="rId2"/>'
            .'</sheets>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function importSheet(array $rows, string $roleFormula, string $firmFormula, string $moduleFormula): string
    {
        $sheetData = $this->sheetDataXml($rows, boldHeader: true);

        // showDropDown="0" means show the arrow (OOXML inverted flag).
        // Modules: each list entry is a dependency-valid package (one or many modules).
        $validations = '<dataValidations count="3">'
            .'<dataValidation type="list" allowBlank="1" showDropDown="0" showErrorMessage="1"'
            .' errorStyle="stop" errorTitle="Invalid role"'
            .' error="Select a role from the list. Power Admin and FinProms Admin cannot be imported."'
            .' sqref="D2:D1048576">'
            .'<formula1>'.$this->esc($roleFormula).'</formula1>'
            .'</dataValidation>'
            .'<dataValidation type="list" allowBlank="1" showDropDown="0" showErrorMessage="1"'
            .' errorStyle="stop" errorTitle="Invalid firm"'
            .' error="Select a firm that already exists on this hub."'
            .' sqref="E2:E1048576">'
            .'<formula1>'.$this->esc($firmFormula).'</formula1>'
            .'</dataValidation>'
            .'<dataValidation type="list" allowBlank="1" showDropDown="0" showErrorMessage="0"'
            .' promptTitle="Modules (multi-select packages)"'
            .' prompt="Pick a package from the list (includes multi-module options that respect dependencies). Leave blank for base hub only. Shared / White Label Hub is always assigned."'
            .' showInputMessage="1"'
            .' sqref="F2:F1048576">'
            .'<formula1>'.$this->esc($moduleFormula).'</formula1>'
            .'</dataValidation>'
            .'</dataValidations>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetData>'.$sheetData.'</sheetData>'
            .$validations
            .'</worksheet>';
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function listsSheet(array $rows): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$this->sheetDataXml($rows, boldHeader: true).'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function sheetDataXml(array $rows, bool $boldHeader = false): string
    {
        $xml = '';
        foreach ($rows as $rIndex => $row) {
            $rowNum = $rIndex + 1;
            $xml .= '<row r="'.$rowNum.'">';
            foreach ($row as $cIndex => $value) {
                $col = $this->columnLetter($cIndex + 1);
                $ref = $col.$rowNum;
                $style = ($boldHeader && $rIndex === 0) ? ' s="1"' : '';
                $xml .= '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t>'
                    .$this->esc((string) $value)
                    .'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml;
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
