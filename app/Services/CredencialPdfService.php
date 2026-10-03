<?php

namespace App\Services;

use App\Models\Personero;
use Exception;
use setasign\Fpdi\Fpdi;

class CredencialPdfService
{
    /**
     * Mapa de configuración de campos para la plantilla:
     * public/img/plantillacentrodevotacion.pdf
     *
     * 7 campos (SIN nro_mesa):
     * - 'clear': Área que se limpia con fondo blanco sobre la plantilla.
     * - 'text': Posición de renderizado del texto, dimensiones, tamaño de fuente y alineación.
     */
    private const CONFIG_CENTRO_VOTACION = [
        'nombre' => [
            'clear' => ['x' => 71.5, 'y' => 63.2, 'w' => 120.0, 'h' => 6.0],
            'text'  => ['x' => 72.0, 'y' => 64.0, 'w' => 118.0, 'h' => 4.5, 'font_size' => 11.0, 'min_font_size' => 6.0, 'align' => 'L']
        ],
        'dni' => [
            'clear' => ['x' => 151.0, 'y' => 71.8, 'w' => 40.0, 'h' => 6.0],
            'text'  => ['x' => 152.0, 'y' => 72.5, 'w' => 38.0, 'h' => 4.5, 'font_size' => 11.0, 'min_font_size' => 8.0, 'align' => 'L']
        ],
        'desc_centro_vota' => [
            'clear' => ['x' => 65.0, 'y' => 88.8, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 89.5, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 5.5, 'align' => 'L']
        ],
        'dir_colegio' => [
            'clear' => ['x' => 65.0, 'y' => 97.3, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 98.0, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 5.5, 'align' => 'L']
        ],
        'desc_distrito' => [
            'clear' => ['x' => 65.0, 'y' => 105.8, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 106.5, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 6.0, 'align' => 'L']
        ],
        'desc_provincia' => [
            'clear' => ['x' => 65.0, 'y' => 114.3, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 115.0, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 6.0, 'align' => 'L']
        ],
        'desc_departamento' => [
            'clear' => ['x' => 65.0, 'y' => 122.8, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 123.5, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 6.0, 'align' => 'L']
        ],
    ];

    /**
     * Mapa de configuración de campos para la plantilla:
     * public/img/plantillamesadesufragio.pdf
     *
     * 8 campos (CON nro_mesa):
     * - 'clear': Área que se limpia con fondo blanco sobre la plantilla.
     * - 'text': Posición de renderizado del texto, dimensiones, tamaño de fuente y alineación.
     */
    private const CONFIG_MESA = [
        'nombre' => [
            'clear' => ['x' => 72.0, 'y' => 60.8, 'w' => 120.0, 'h' => 6.0],
            'text'  => ['x' => 72.8, 'y' => 61.5, 'w' => 118.0, 'h' => 4.5, 'font_size' => 11.0, 'min_font_size' => 6.0, 'align' => 'L']
        ],
        'dni' => [
            'clear' => ['x' => 151.0, 'y' => 69.3, 'w' => 40.0, 'h' => 6.0],
            'text'  => ['x' => 152.0, 'y' => 70.0, 'w' => 38.0, 'h' => 4.5, 'font_size' => 11.0, 'min_font_size' => 8.0, 'align' => 'L']
        ],
        'nro_mesa' => [
            'clear' => ['x' => 92.0, 'y' => 86.3, 'w' => 99.0, 'h' => 6.0],
            'text'  => ['x' => 92.8, 'y' => 87.0, 'w' => 97.0, 'h' => 4.5, 'font_size' => 11.0, 'min_font_size' => 7.0, 'align' => 'L']
        ],
        'desc_centro_vota' => [
            'clear' => ['x' => 65.0, 'y' => 103.3, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 104.0, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 5.5, 'align' => 'L']
        ],
        'dir_colegio' => [
            'clear' => ['x' => 65.0, 'y' => 111.8, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 112.5, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 5.5, 'align' => 'L']
        ],
        'desc_distrito' => [
            'clear' => ['x' => 65.0, 'y' => 120.3, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 121.0, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 6.0, 'align' => 'L']
        ],
        'desc_provincia' => [
            'clear' => ['x' => 65.0, 'y' => 128.8, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 129.5, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 6.0, 'align' => 'L']
        ],
        'desc_departamento' => [
            'clear' => ['x' => 65.0, 'y' => 137.3, 'w' => 126.0, 'h' => 6.0],
            'text'  => ['x' => 65.5, 'y' => 138.0, 'w' => 124.5, 'h' => 4.5, 'font_size' => 10.5, 'min_font_size' => 6.0, 'align' => 'L']
        ],
    ];

    public function __construct()
    {
        if (!class_exists('FPDF')) {
            class_alias(\Fpdf\Fpdf::class, 'FPDF');
        }
    }

    /**
     * Resuelve la plantilla y la configuración de campos según desc_tipo_personero
     *
     * @param Personero $personero
     * @return array
     * @throws Exception
     */
    public function getTemplateConfig(Personero $personero): array
    {
        $tipo = trim((string)$personero->desc_tipo_personero);
        $tipoNorm = mb_strtolower($tipo, 'UTF-8');
        // Remover tildes para comparación flexible
        $tipoNormSinTildes = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $tipoNorm
        );

        if (
            $tipo === 'Personero de centro de votación' ||
            str_contains($tipoNormSinTildes, 'centro de votacion')
        ) {
            return [
                'template_file' => 'plantillacentrodevotacion.pdf',
                'config'        => self::CONFIG_CENTRO_VOTACION,
                'include_mesa'  => false,
                'tipo_label'    => 'Personero de centro de votación'
            ];
        }

        if (
            $tipo === 'Personero de mesa' ||
            str_contains($tipoNormSinTildes, 'personero de mesa') ||
            $tipoNormSinTildes === 'mesa'
        ) {
            return [
                'template_file' => 'plantillamesadesufragio.pdf',
                'config'        => self::CONFIG_MESA,
                'include_mesa'  => true,
                'tipo_label'    => 'Personero de mesa'
            ];
        }

        $mostrarTipo = $tipo !== '' ? $tipo : '(vacío)';
        throw new Exception("No existe una plantilla configurada para el tipo de personero: '{$mostrarTipo}'.");
    }

    /**
     * Obtiene la ruta del archivo plantilla asegurando compatibilidad con FPDI
     *
     * @param string $templateFilename
     * @return string
     * @throws Exception
     */
    public function getTemplatePath(string $templateFilename): string
    {
        $path = public_path('img/' . $templateFilename);
        if (!file_exists($path)) {
            $fallback = base_path('public/img/' . $templateFilename);
            if (file_exists($fallback)) {
                $path = $fallback;
            } else {
                throw new Exception("El archivo plantilla oficial {$templateFilename} no fue encontrado en {$path}");
            }
        }

        // 1. Probar si FPDI puede abrir directamente el PDF
        try {
            $testPdf = new Fpdi();
            $testPdf->setSourceFile($path);
            $testPdf->importPage(1);
            return $path;
        } catch (\Throwable $e) {
            // Requiere versión normalizada por compresión de flujos PDF 1.5+
        }

        $baseName = pathinfo($templateFilename, PATHINFO_FILENAME);

        // 2. Verificar versión pre-generada en public/img
        $publicCompatible = public_path("img/{$baseName}_compatible.pdf");
        if (file_exists($publicCompatible)) {
            try {
                $testPdf = new Fpdi();
                $testPdf->setSourceFile($publicCompatible);
                $testPdf->importPage(1);
                return $publicCompatible;
            } catch (\Throwable $e) {
                // Continuar a cache si falla
            }
        }

        // 3. Gestionar versión compatible normalizada en cache storage
        $cacheDir = storage_path('app/templates');
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $normalizedPath = $cacheDir . "/{$baseName}_compatible.pdf";

        $needsRebuild = !file_exists($normalizedPath) || (filemtime($path) > filemtime($normalizedPath));
        if ($needsRebuild) {
            $pyScript = escapeshellarg("import pypdf, io; f=open(r'{$path}', 'rb'); data=f.read(); f.close(); r=pypdf.PdfReader(io.BytesIO(data)); w=pypdf.PdfWriter(); w.add_page(r.pages[0]); out=open(r'{$normalizedPath}', 'wb'); w.write(out); out.close()");
            @exec("python -c {$pyScript} 2>&1", $output, $resultCode);
        }

        if (file_exists($normalizedPath)) {
            return $normalizedPath;
        }

        if (file_exists($publicCompatible)) {
            return $publicCompatible;
        }

        return $path;
    }

    /**
     * Dibuja los datos de un personero sobre una página limpia de la plantilla PDF
     */
    private function renderPersoneroPage(Fpdi $pdf, $tplIdx, Personero $personero, array $fieldConfig, bool $includeMesa): void
    {
        $pdf->AddPage('P', 'A4');
        $pdf->useTemplate($tplIdx, 0, 0, 210, 297);

        $data = [
            'nombre'            => $personero->nombre,
            'dni'               => $personero->dni,
            'desc_centro_vota'  => $personero->desc_centro_vota,
            'dir_colegio'       => $personero->dir_colegio,
            'desc_distrito'     => $personero->desc_distrito,
            'desc_provincia'    => $personero->desc_provincia,
            'desc_departamento' => $personero->desc_departamento,
        ];

        // Incluir nro_mesa ÚNICAMENTE si la plantilla lo admite (Personero de mesa)
        if ($includeMesa) {
            $data['nro_mesa'] = $personero->nro_mesa;
        }

        foreach ($fieldConfig as $key => $config) {
            // 1. Limpieza incondicional del placeholder de la plantilla con fondo blanco
            $pdf->SetFillColor(255, 255, 255);
            $c = $config['clear'];
            $pdf->Rect($c['x'], $c['y'], $c['w'], $c['h'], 'F');

            // 2. Si existe un valor dinámico válido, escribirlo adaptando progresivamente el tamaño de fuente
            $val = trim((string)($data[$key] ?? ''));
            $lower = strtolower($val);

            if ($val !== '' && $lower !== 'null' && $lower !== 'undefined') {
                $t = $config['text'];
                $encodedText = mb_convert_encoding($val, 'ISO-8859-1', 'UTF-8');
                $fontSize = $t['font_size'];
                $minFontSize = $t['min_font_size'] ?? 6.0;

                $pdf->SetFont('Arial', 'B', $fontSize);
                while ($pdf->GetStringWidth($encodedText) > ($t['w'] - 1.5) && $fontSize > $minFontSize) {
                    $fontSize -= 0.25;
                    $pdf->SetFont('Arial', 'B', $fontSize);
                }

                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetXY($t['x'], $t['y']);
                $pdf->Cell($t['w'], $t['h'], $encodedText, 0, 0, $t['align']);
            }
        }
    }

    /**
     * Genera el PDF individual de un personero y devuelve su contenido binario
     *
     * @param Personero $personero
     * @return string
     * @throws Exception
     */
    public function generateSingle(Personero $personero): string
    {
        $templateInfo = $this->getTemplateConfig($personero);
        $templatePath = $this->getTemplatePath($templateInfo['template_file']);

        $pdf = new Fpdi();
        $pdf->setSourceFile($templatePath);
        $tplIdx = $pdf->importPage(1);

        $this->renderPersoneroPage(
            $pdf,
            $tplIdx,
            $personero,
            $templateInfo['config'],
            $templateInfo['include_mesa']
        );

        return $pdf->Output('S');
    }

    /**
     * Genera un único PDF masivo multipágina con una credencial limpia por cada personero,
     * seleccionando dinámicamente la plantilla correspondiente a cada registro.
     *
     * @param iterable|array $personeros
     * @return string
     * @throws Exception
     */
    public function generateBatch($personeros): string
    {
        $pdf = new Fpdi();
        $importedTemplates = [];

        foreach ($personeros as $personero) {
            $templateInfo = $this->getTemplateConfig($personero);
            $templateFile = $templateInfo['template_file'];

            // Importar y cachear plantilla por archivo para reutilizar en el mismo documento
            if (!isset($importedTemplates[$templateFile])) {
                $templatePath = $this->getTemplatePath($templateFile);
                $pdf->setSourceFile($templatePath);
                $importedTemplates[$templateFile] = $pdf->importPage(1);
            }

            $tplIdx = $importedTemplates[$templateFile];

            $this->renderPersoneroPage(
                $pdf,
                $tplIdx,
                $personero,
                $templateInfo['config'],
                $templateInfo['include_mesa']
            );
        }

        return $pdf->Output('S');
    }
}
