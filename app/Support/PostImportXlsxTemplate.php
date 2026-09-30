<?php

namespace App\Support;

/**
 * Excel template for bulk-creating Central library posts (manual).
 */
class PostImportXlsxTemplate
{
    public function __construct(
        private readonly XlsxZipBuilder $zip = new XlsxZipBuilder
    ) {}

    /**
     * @param  list<string>  $typeNames
     * @param  list<string>  $categoryNames
     * @param  list<string>  $tagNames
     */
    public function build(array $typeNames = [], array $categoryNames = [], array $tagNames = []): string
    {
        $typeNames = array_values(array_filter(array_map('strval', $typeNames), fn ($v) => trim($v) !== ''));
        $categoryNames = array_values(array_filter(array_map('strval', $categoryNames), fn ($v) => trim($v) !== ''));
        $tagNames = array_values(array_filter(array_map('strval', $tagNames), fn ($v) => trim($v) !== ''));

        $sampleType = $typeNames[0] ?? 'Post';
        $sampleCategory = $categoryNames[0] ?? '';
        $sampleTag = $tagNames[0] ?? '';

        $importRows = [
            ['title', 'description', 'type', 'categories', 'tags', 'credits_cost', 'canva_link', 'is_active'],
            [
                'Sample post title',
                'Optional description for the post.',
                $sampleType,
                $sampleCategory,
                $sampleTag,
                '10',
                '',
                'yes',
            ],
        ];

        $listRows = [['type', 'category', 'tag']];
        $max = max(count($typeNames), count($categoryNames), count($tagNames), 1);
        for ($i = 0; $i < $max; $i++) {
            $listRows[] = [
                $typeNames[$i] ?? '',
                $categoryNames[$i] ?? '',
                $tagNames[$i] ?? '',
            ];
        }

        $typeEnd = max(count($typeNames), 1) + 1;
        $typeFormula = count($typeNames) > 0
            ? 'Lists!$A$2:$A$'.$typeEnd
            : '"Post"';

        $files = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->relsXml(),
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelsXml(),
            'xl/styles.xml' => $this->stylesXml(),
            'xl/worksheets/sheet1.xml' => $this->sheetXml($importRows, [
                'C' => $typeFormula,
            ]),
            'xl/worksheets/sheet2.xml' => $this->sheetXml($listRows),
        ];

        return $this->zip->build($files);
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, string>  $dataValidations  column letter => formula
     */
    private function sheetXml(array $rows, array $dataValidations = []): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetData>';

        foreach ($rows as $rIdx => $row) {
            $rowNum = $rIdx + 1;
            $xml .= '<row r="'.$rowNum.'">';
            foreach ($row as $cIdx => $value) {
                $cell = $this->colLetter($cIdx).$rowNum;
                $xml .= $this->inlineStringCell($cell, (string) $value);
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        if ($dataValidations !== []) {
            $xml .= '<dataValidations count="'.count($dataValidations).'">';
            foreach ($dataValidations as $col => $formula) {
                $sqref = $col.'2:'.$col.'1000';
                $xml .= '<dataValidation type="list" allowBlank="1" showDropDown="0" sqref="'.$sqref.'">'
                    .'<formula1>'.$this->xml($formula).'</formula1>'
                    .'</dataValidation>';
            }
            $xml .= '</dataValidations>';
        }

        $xml .= '</worksheet>';

        return $xml;
    }

    private function inlineStringCell(string $ref, string $value): string
    {
        return '<c r="'.$ref.'" t="inlineStr"><is><t>'.$this->xml($value).'</t></is></c>';
    }

    private function colLetter(int $index): string
    {
        $letter = '';
        $n = $index;
        do {
            $letter = chr(65 + ($n % 26)).$letter;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $letter;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypesXml(): string
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

    private function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'
            .'<sheet name="Import" sheetId="1" r:id="rId1"/>'
            .'<sheet name="Lists" sheetId="2" r:id="rId2"/>'
            .'</sheets>'
            .'</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            .'</styleSheet>';
    }
}
