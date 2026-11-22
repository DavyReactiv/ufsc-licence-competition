<?php
/** Diagnostic-only regression for PDF rendering. */
$src=file_get_contents(dirname(__DIR__).'/includes/class-ufsc-lc-licence-pdf-generator.php');
$start=strpos($src,'private static function render_pdf(');
$end=strpos($src,'private static function build_a4_fallback_markup',$start);
$render=substr($src,$start,$end-$start);
$checks=array(
    'Renderer catches PHP Error and Exception' => strpos($render,'catch ( Throwable $exception )')!==false,
    'Error includes safe class and location in PHP log' => strpos($render,'UFSC licence PDF render failure: %s (%s:%d)')!==false
        && strpos($render,'basename( $exception->getFile() )')!==false,
    'Generic user-facing error remains safe' => strpos($render,"'ufsc_lc_pdf_render_error'")!==false,
    'A4 single page check unchanged' => strpos($render,'ufsc_lc_pdf_a4_validation_failed')!==false,
    'Manual endpoint records error code only' => strpos($src,'UFSC licence PDF manual generation failed: ')!==false,
    'No licence metadata logging' => strpos($render,'$exception->getMessage()')===false
        && strpos($render,'$html')!==false,
    'No licence state mutation in renderer' => strpos($render,'update_post_meta(')===false,
);
foreach($checks as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)exit(1);}
