<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use PHPUnit\Framework\TestCase;
use Rankly\Gerador\ScriptSite;

final class ScriptSiteTest extends TestCase
{
    public function testSemRastreioNaoCarregaTerceirosNemFaixa(): void
    {
        $js = ScriptSite::gerar(['gtm' => '', 'ga4' => '', 'metaPixel' => ''], ['formulario' => true, 'mapa' => true]);
        $this->assertStringContainsString('"gtm":""', $js);
        $this->assertStringNotContainsString('googletagmanager', $js);
        $this->assertStringNotContainsString('rk-consent', $js);
        $this->assertStringContainsString("fetch(f.getAttribute('action')||'/_lead'", $js);
        $this->assertStringContainsString("'X-Requested-With':'XMLHttpRequest'", $js);
        $this->assertStringContainsString('rk_origem', $js);
        $this->assertStringContainsString('data-mapa', $js);
        $this->assertLessThan(3500, strlen($js), 'site sem rastreamento: script pequeno (~3 KB)');
    }

    public function testComRastreioTemConsentimentoNegadoPorPadrao(): void
    {
        $js = ScriptSite::gerar(['gtm' => 'gtm-abc1234', 'ga4' => 'G-XYZ98765', 'metaPixel' => '1234567890'], ['formulario' => true, 'mapa' => false]);
        $this->assertStringContainsString('"gtm":"GTM-ABC1234"', $js, 'ID normalizado em maiúsculas');
        $this->assertStringContainsString("w.gtag('consent','default',{ad_storage:'denied'", $js);
        $this->assertStringContainsString("'rk_consentimento'", $js);
        $this->assertStringContainsString('rankly_whatsapp_click', $js);
        $this->assertStringContainsString('rankly_phone_click', $js);
        $this->assertStringContainsString('rankly_form_submit', $js);
        $this->assertStringNotContainsString('data-mapa', $js);
        $this->assertLessThan(7000, strlen($js));
    }

    public function testIdsInvalidosSaoIgnorados(): void
    {
        $js = ScriptSite::gerar(['gtm' => 'GTM-1"};alert(1)//', 'ga4' => '<script>', 'metaPixel' => 'abc'], []);
        $this->assertStringContainsString('{"gtm":"","ga4":"","pixel":""}', $js);
        $this->assertStringNotContainsString('alert', $js);
        $this->assertStringNotContainsString('</', $js, 'nada que feche a tag <script>');
    }

    public function testHashCsp(): void
    {
        $js = ScriptSite::gerar([], []);
        $this->assertSame("'sha256-" . base64_encode(hash('sha256', $js, true)) . "'", ScriptSite::hashCsp($js));
    }

    public function testMinificacaoMantemStrings(): void
    {
        $this->assertSame("var a='x  =  y',b=\"c , d\";f(a,b);", ScriptSite::minificar("  // comentário\n  var a = 'x  =  y', b = \"c , d\";\n\n  f( a , b );"));
        $this->assertSame("a=b\nc()", ScriptSite::minificar("a = b\nc()"), 'sem ";" no fim, a quebra de linha fica');
    }

    /** O JavaScript gerado precisa ser válido (node --check), em todas as combinações. */
    public function testSintaxeValidaNoNode(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node não está disponível.');
        }
        $dir = sys_get_temp_dir() . '/rankly-js-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $combinacoes = [
                [[], []],
                [[], ['formulario' => true]],
                [['gtm' => 'GTM-ABCD123'], ['formulario' => true, 'mapa' => true]],
                [['ga4' => 'G-ABCDE12345', 'metaPixel' => '123456789'], ['mapa' => true]],
            ];
            foreach ($combinacoes as $i => [$rastreamento, $usa]) {
                $arquivo = "{$dir}/s{$i}.js";
                file_put_contents($arquivo, ScriptSite::gerar($rastreamento, $usa));
                exec(escapeshellarg($node) . ' --check ' . escapeshellarg($arquivo) . ' 2>&1', $saida, $codigo);
                $this->assertSame(0, $codigo, "combinação {$i}: " . implode("\n", $saida));
            }
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
