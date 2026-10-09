<?php

declare(strict_types=1);

/**
 * Renderiza em lote no PHP (lado da publicação) para o teste de paridade [M8].
 *   php tests/paridade/renderizar.php entrada.json saida.json
 * entrada = {biblioteca: dir, casos: [{nome, doc, opcoes}], funcoes: [{nome, fn, args}], bundle?: bool}
 * saída   = {versao, bundle?, resultados: [...], funcoes: [...]}
 */

use Rankly\Preparo\Biblioteca;
use Rankly\Preparo\Dados;
use Rankly\Preparo\Documento;
use Rankly\Preparo\Fotos;
use Rankly\Preparo\Html;
use Rankly\Preparo\Icones;
use Rankly\Preparo\Paleta;
use Rankly\Preparo\Preparo;
use Rankly\Preparo\Texto;
use Rankly\Preparo\Textos;
use Rankly\Preparo\Tons;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

ini_set('serialize_precision', '-1');
error_reporting(E_ALL);
set_error_handler(static function (int $nivel, string $mensagem, string $arquivo, int $linha): bool {
    throw new ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
});

/** Mesmo registro de tests/paridade/funcoes.mjs. */
const FUNCOES = [
    'Texto.semAcentos' => [Texto::class, 'semAcentos'],
    'Texto.normalizar' => [Texto::class, 'normalizar'],
    'Texto.colapsarEspacos' => [Texto::class, 'colapsarEspacos'],
    'Texto.aparar' => [Texto::class, 'aparar'],
    'Texto.escapeHtml' => [Texto::class, 'escapeHtml'],
    'Texto.arred' => [Texto::class, 'arred'],
    'Texto.codificarUri' => [Texto::class, 'codificarUri'],
    'Paleta.hexParaRgb' => [Paleta::class, 'hexParaRgb'],
    'Paleta.normalizarCor' => [Paleta::class, 'normalizarCor'],
    'Paleta.rgbParaHsl' => [Paleta::class, 'rgbParaHsl'],
    'Paleta.hslParaHex' => [Paleta::class, 'hslParaHex'],
    'Paleta.luminancia' => [Paleta::class, 'luminancia'],
    'Paleta.contraste' => [Paleta::class, 'contraste'],
    'Paleta.gerarPaleta' => [Paleta::class, 'gerarPaleta'],
    'Paleta.cssPaleta' => [Paleta::class, 'cssPaleta'],
    'Tons.calcularFundos' => [Tons::class, 'calcularFundos'],
    'Icones.escolherIcone' => [Icones::class, 'escolherIcone'],
    'Icones.iconeDoItem' => [Icones::class, 'iconeDoItem'],
    'Icones.svg' => [Icones::class, 'svg'],
    'Dados.soDigitos' => [Dados::class, 'soDigitos'],
    'Dados.validarWhatsapp' => [Dados::class, 'validarWhatsapp'],
    'Dados.formatarTelefone' => [Dados::class, 'formatarTelefone'],
    'Dados.linkWhatsapp' => [Dados::class, 'linkWhatsapp'],
    'Dados.linkTelefone' => [Dados::class, 'linkTelefone'],
    'Dados.validarEmail' => [Dados::class, 'validarEmail'],
    'Dados.formatarCep' => [Dados::class, 'formatarCep'],
    'Dados.linhasEndereco' => [Dados::class, 'linhasEndereco'],
    'Dados.formatarEndereco' => [Dados::class, 'formatarEndereco'],
    'Dados.formatarHora' => [Dados::class, 'formatarHora'],
    'Dados.formatarHorarios' => [Dados::class, 'formatarHorarios'],
    'Dados.formatarRegistro' => [Dados::class, 'formatarRegistro'],
    'Dados.inicial' => [Dados::class, 'inicial'],
    'Dados.urlRede' => [Dados::class, 'urlRede'],
    'Textos.contextoVariaveis' => [Textos::class, 'contextoVariaveis'],
    'Textos.substituirVariaveis' => [Textos::class, 'substituirVariaveis'],
    'Textos.textoPadrao' => [Textos::class, 'textoPadrao'],
    'Textos.textoEfetivo' => [Textos::class, 'textoEfetivo'],
    'Textos.ehPadrao' => [Textos::class, 'ehPadrao'],
    'Textos.retokenizar' => [Textos::class, 'retokenizar'],
    'Textos.itensLista' => [Textos::class, 'itensLista'],
    'Textos.textosPadraoEfetivos' => [Textos::class, 'textosPadraoEfetivos'],
    'Textos.aplicarEdicaoTexto' => [Textos::class, 'aplicarEdicaoTexto'],
    'Textos.adicionarItem' => [Textos::class, 'adicionarItem'],
    'Textos.removerItem' => [Textos::class, 'removerItem'],
    'Textos.moverItem' => [Textos::class, 'moverItem'],
    'Documento.criarDocumento' => [Documento::class, 'criarDocumento'],
    'Fotos.generoDoNome' => [Fotos::class, 'generoDoNome'],
    'Fotos.imagensDeExemplo' => [Fotos::class, 'imagensDeExemplo'],
    'Documento.receitaModelo' => [Documento::class, 'receitaModelo'],
    'Documento.aplicarModelo' => [Documento::class, 'aplicarModelo'],
    'Documento.migrar' => [Documento::class, 'migrar'],
    'Documento.registroCampos' => [Documento::class, 'registroCampos'],
    'Documento.registroListas' => [Documento::class, 'registroListas'],
    'Documento.especialidade' => [Documento::class, 'especialidade'],
    'Documento.completarDados' => [Documento::class, 'completarDados'],
    'Html.imagem' => [Html::class, 'imagem'],
    'Html.logo' => [Html::class, 'logo'],
    'Preparo.montarDados' => [Preparo::class, 'montarDados'],
    'Preparo.renderizarTemplate' => [Preparo::class, 'renderizarTemplate'],
];

[, $entrada, $saida] = $argv + [null, null, null];
if ($entrada === null) {
    fwrite(STDERR, "uso: php tests/paridade/renderizar.php entrada.json [saida.json]\n");
    exit(2);
}
$pedido = json_decode((string) file_get_contents($entrada), true, 512, JSON_THROW_ON_ERROR);
$lib = Biblioteca::carregar($pedido['biblioteca']);

$resultados = [];
foreach ($pedido['casos'] ?? [] as $caso) {
    try {
        $resultados[] = ['nome' => $caso['nome']] + Preparo::prepararSite($caso['doc'], $lib, $caso['opcoes'] ?? []);
    } catch (Throwable $e) {
        $resultados[] = ['nome' => $caso['nome'], 'erro' => get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()];
    }
}

$funcoes = [];
foreach ($pedido['funcoes'] ?? [] as $chamada) {
    $fn = FUNCOES[$chamada['fn']] ?? null;
    if ($fn === null) {
        $funcoes[] = ['nome' => $chamada['nome'], 'erro' => 'função desconhecida: ' . $chamada['fn']];
        continue;
    }
    // "$lib" vira a biblioteca; "$lib.a.b", um pedaço dela (como resolverArgumento() em funcoes.mjs).
    $args = array_map(static function ($a) use ($lib) {
        if ($a === '$lib') {
            return $lib;
        }
        if (is_string($a) && str_starts_with($a, '$lib.')) {
            $v = $lib;
            foreach (explode('.', substr($a, 5)) as $k) {
                $v = is_array($v) ? ($v[$k] ?? null) : null;
            }
            return $v;
        }
        return $a;
    }, $chamada['args'] ?? []);
    try {
        $funcoes[] = ['nome' => $chamada['nome'], 'resultado' => $fn(...$args)];
    } catch (InvalidArgumentException $e) {
        $funcoes[] = ['nome' => $chamada['nome'], 'erro' => $e->getMessage()];
    } catch (Throwable $e) {
        $funcoes[] = ['nome' => $chamada['nome'], 'erro' => get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()];
    }
}

$resposta = ['versao' => $lib['versao'], 'resultados' => $resultados, 'funcoes' => $funcoes];
if (!empty($pedido['bundle'])) {
    $resposta['bundle'] = $lib;
}
$json = json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
if ($saida !== null) {
    file_put_contents($saida, $json);
} else {
    echo $json;
}
