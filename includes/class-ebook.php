<?php
/**
 * Dogology_Ebook — gated, per-buyer-stamped ebook PDF delivery.
 *
 * An "ebook" is a dogology_course with meta:
 *   _dogology_format    = 'ebook'
 *   _dogology_ebook_pdf = filename inside the protected dir (never media library)
 *
 * Storage layout (all inside wp-content/dogology-ebooks/, .htaccess-denied):
 *   <file>.pdf                      source PDF (uploaded via Courses admin)
 *   cache/<course>-<user>-<fp>.pdf  stamped per-user output (fp = source fingerprint,
 *                                   so re-uploading the source auto-invalidates)
 *   fonts/unifont/Kanit-Regular.ttf tFPDF working copy (metrics cache written beside it)
 *
 * Stamp: one muted-grey Kanit footer line on every page —
 *   "จัดทำสำหรับ คุณ{name} · dogology.org"
 * Soft social deterrent + the personalization touch; name only, no email (PII).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FPDI subclass fixing tFPDF's Thai string-width bug: zero-width combining
 * marks (สระบน/ล่าง + วรรณยุกต์, e.g. U+0E31) are stored in cw.dat as the
 * sentinel 0xFFFF, and stock GetStringWidth() adds that as a real 65535-unit
 * advance — every mark inflates the width by ~163mm, throwing centered text
 * ~1200pt off-page. Combining marks have zero advance; count them as 0.
 * (Defined lazily inside Dogology_Ebook::load_libs() so this file can load
 * before composer's autoloader is required.)
 */
function dogology_ebook_define_fpdi_subclass()
{
    if (class_exists('Dogology_Fpdi_Thai', false)) {
        return;
    }
    class Dogology_Fpdi_Thai extends \setasign\Fpdi\Tfpdf\Fpdi
    {
        function GetStringWidth($s)
        {
            $s = (string) $s;
            if (!$this->unifontSubset) {
                return parent::GetStringWidth($s);
            }
            $cw = $this->CurrentFont['cw'];
            $w = 0;
            $unicode = $this->UTF8StringToArray($s);
            foreach ($unicode as $char) {
                if (isset($cw[2 * $char])) {
                    $cwv = (ord($cw[2 * $char]) << 8) + ord($cw[2 * $char + 1]);
                    if ($cwv !== 65535) { // 0xFFFF = zero-width combining mark
                        $w += $cwv;
                    }
                } elseif ($char > 0 && $char < 128 && isset($cw[chr($char)])) {
                    $w += $cw[chr($char)];
                } elseif (isset($this->CurrentFont['desc']['MissingWidth'])) {
                    $w += $this->CurrentFont['desc']['MissingWidth'];
                } elseif (isset($this->CurrentFont['MissingWidth'])) {
                    $w += $this->CurrentFont['MissingWidth'];
                } else {
                    $w += 500;
                }
            }
            return $w * $this->FontSize / 1000;
        }
    }
}

class Dogology_Ebook
{
    const FORMAT_META = '_dogology_format';
    const PDF_META    = '_dogology_ebook_pdf';

    /**
     * Protected storage dir (created on demand, web access denied).
     * Same pattern as class-integration-commerce.php's dogology-logs dir.
     */
    public static function dir()
    {
        $dir = WP_CONTENT_DIR . '/dogology-ebooks';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        foreach (array('/cache', '/fonts', '/fonts/unifont') as $sub) {
            if (!file_exists($dir . $sub)) {
                wp_mkdir_p($dir . $sub);
            }
        }
        return $dir;
    }

    /** Whether a course post is an ebook. */
    public static function is_ebook($course_id)
    {
        return get_post_meta($course_id, self::FORMAT_META, true) === 'ebook';
    }

    /** Absolute path of a course's source PDF, or '' when unset/missing. */
    public static function source_path($course_id)
    {
        $file = get_post_meta($course_id, self::PDF_META, true);
        if (!$file) {
            return '';
        }
        // filename only — no traversal out of the protected dir
        $file = basename($file);
        $path = self::dir() . '/' . $file;
        return file_exists($path) ? $path : '';
    }

    /**
     * Compatibility probe: can FPDI parse this PDF? Run at upload time so an
     * incompatible file (compressed xref > PDF 1.5) fails loudly in admin,
     * never at buyer download time. Returns true|WP_Error.
     */
    public static function probe($path)
    {
        try {
            self::load_libs();
            $pdf = new Dogology_Fpdi_Thai();
            $pages = $pdf->setSourceFile($path);
            if ($pages < 1) {
                return new WP_Error('dl_ebook_empty', 'PDF has no pages.');
            }
            $pdf->importPage(1);
            return true;
        } catch (\Throwable $e) {
            return new WP_Error('dl_ebook_incompatible', 'FPDI cannot parse this PDF: ' . $e->getMessage()
                . ' — normalize it first (qpdf --object-streams=disable in.pdf out.pdf).');
        }
    }

    /**
     * Stream the stamped PDF for a student. Ends the request (exit) —
     * call only from the download route. Assumes access checks already passed.
     */
    public static function stream_for($course_id, $student)
    {
        $source = self::source_path($course_id);
        if (!$source) {
            self::fail(404, 'ยังไม่มีไฟล์หนังสือ กรุณาติดต่อเรา ทาง LINE @dogology');
        }

        $name = trim((string) ($student->display_name ?: $student->email));
        $cache = self::dir() . '/cache/' . $course_id . '-' . $student->id . '-'
            . md5($name . '|' . filemtime($source) . '|' . filesize($source)) . '.pdf';

        if (!file_exists($cache)) {
            try {
                self::stamp($source, $cache, $name);
            } catch (\Throwable $e) {
                error_log('[dogology-ebook] stamp failed for course ' . $course_id . ': ' . $e->getMessage());
                // Fail open: a buyer must never be blocked from the book they
                // paid for by a stamping bug — serve the unstamped source.
                $cache = $source;
            }
        }

        $post = get_post($course_id);
        $slug = $post && $post->post_name ? $post->post_name : ('dogology-ebook-' . $course_id);
        self::send_file($cache, $slug . '.pdf');
    }

    /** FPDI/tFPDF import + per-page footer stamp. Atomic write (tmp + rename). */
    protected static function stamp($source, $dest, $name)
    {
        self::load_libs();
        self::ensure_font();

        $pdf = new Dogology_Fpdi_Thai();
        $pdf->SetAutoPageBreak(false);
        $pdf->AddFont('Kanit', '', 'Kanit-Regular.ttf', true);

        $label = 'จัดทำสำหรับ คุณ' . $name . ' · dogology.org';

        $pages = $pdf->setSourceFile($source);
        for ($p = 1; $p <= $pages; $p++) {
            $tpl  = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], array($size['width'], $size['height']));
            $pdf->useTemplate($tpl);

            // footer stamp — small, muted, centered, inside the bottom margin.
            // Page 1 (the photo cover) stays clean; stamp starts on page 2.
            if ($p > 1) {
                $pdf->SetFont('Kanit', '', 7);
                $pdf->SetTextColor(160, 165, 175); // slate-ish grey, quieter than body text
                $pdf->SetXY(0, $size['height'] - 6.5);
                $pdf->Cell($size['width'], 4, $label, 0, 0, 'C');
            }
        }

        $tmp = $dest . '.tmp-' . wp_generate_password(6, false);
        $pdf->Output('F', $tmp);
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new RuntimeException('could not move stamped PDF into cache');
        }
    }

    /** Composer deps (FPDI + tFPDF). */
    protected static function load_libs()
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $autoload = DOGOLOGY_LEARNING_PATH . 'vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new RuntimeException('vendor/autoload.php missing — run composer install in dogology-learning');
        }
        require_once $autoload;
        dogology_ebook_define_fpdi_subclass();
        $loaded = true;
    }

    /**
     * tFPDF needs its TTF inside FPDF_FONTPATH/unifont/ and writes metrics
     * cache files beside it, so we work from the (writable) protected dir,
     * seeding it from the bundled plugin copy.
     */
    protected static function ensure_font()
    {
        $fontdir = self::dir() . '/fonts/';
        $ttf = $fontdir . 'unifont/Kanit-Regular.ttf';
        if (!file_exists($ttf)) {
            copy(DOGOLOGY_LEARNING_PATH . 'assets/fonts/Kanit-Regular.ttf', $ttf);
        }
        if (!defined('FPDF_FONTPATH')) {
            define('FPDF_FONTPATH', $fontdir);
        }
    }

    /** Stream a PDF to the browser and exit. */
    protected static function send_file($path, $filename)
    {
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
            header('Content-Length: ' . filesize($path));
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        // discard any buffered theme/plugin output so the PDF bytes are clean
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    /** Minimal bilingual error page (matches the plugin's wp_die style). */
    protected static function fail($code, $message_th)
    {
        wp_die(
            esc_html($message_th),
            'Dogology',
            array('response' => $code)
        );
    }
}
