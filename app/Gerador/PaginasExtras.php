<?php

declare(strict_types=1);

namespace Rankly\Gerador;

use Rankly\Preparo\Texto;

/**
 * Páginas extras do site publicado [M24]: /privacidade/ (política gerada a partir dos dados),
 * /obrigado/ (conversão do formulário sem JavaScript; noindex) e 404.html — todas com o mesmo
 * cabeçalho e rodapé da página inicial.
 */
final class PaginasExtras
{
    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /**
     * Cabeçalho e rodapé da página inicial, prontos para outra página: sem atributos de
     * edição e com os links de âncora (#servicos) apontando para a inicial (/#servicos).
     *
     * @return array{0: string, 1: string}
     */
    public static function cabecalhoERodape(Montagem $m): array
    {
        $cabecalho = '';
        $rodape = '';
        foreach ($m->secoes() as $s) {
            if ($s['tipo'] === 'header' && $cabecalho === '') {
                $cabecalho = $s['html'];
            } elseif ($s['tipo'] === 'rodape') {
                $rodape = $s['html'];
            }
        }
        return [self::linksParaInicial(Montagem::semAtributosDeEdicao($cabecalho)), self::linksParaInicial(Montagem::semAtributosDeEdicao($rodape))];
    }

    /** href="#ancora" → href="/#ancora" (menos o "pular para o conteúdo" e o "voltar ao topo"). */
    public static function linksParaInicial(string $html): string
    {
        return preg_replace_callback('/<a\s[^>]*>/', static function (array $m): string {
            if (preg_match('/class="[^"]*\b(rk-pular|rod-topo)\b/', $m[0])) {
                return $m[0];
            }
            return preg_replace('/\shref="#([a-z][a-z0-9-]*)"/', ' href="/#$1"', $m[0]) ?? $m[0];
        }, $html) ?? $html;
    }

    /** "6 de outubro de 2026" */
    public static function dataExtenso(\DateTimeImmutable $t): string
    {
        $t = $t->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
        return (int) $t->format('j') . ' de ' . self::MESES[(int) $t->format('n') - 1] . ' de ' . $t->format('Y');
    }

    /** Invólucro de conteúdo das páginas extras (mesmo vocabulário de base.css). */
    private static function secao(string $conteudo): string
    {
        return '<main class="rk-sec rk-sec--pagina rk-bg--tom" id="conteudo"><div class="rk-wrap rk-pagina">' . $conteudo . '</div></main>';
    }

    private static function botaoWhatsapp(Montagem $m, string $posicao, string $rotulo = 'Conversar no WhatsApp'): string
    {
        if (!$m->d['temWhatsapp']) {
            return '';
        }
        return '<a class="rk-btn" href="' . Texto::escapeHtml($m->d['whatsappLink']) . '" data-ev="whatsapp" data-pos="' . $posicao
            . '" target="_blank" rel="noopener"><span class="rk-i">' . ($m->d['u']['whatsapp'] ?? '') . '</span><span>'
            . Texto::escapeHtml($rotulo) . '</span></a>';
    }

    /**
     * Política de privacidade (LGPD) gerada a partir dos dados do site.
     *
     * @param list<string> $rastreadores nomes dos rastreadores configurados
     */
    public static function privacidade(Montagem $m, array $rastreadores, int $retencaoMeses, \DateTimeImmutable $data): string
    {
        $e = static fn (string $s): string => Texto::escapeHtml($s);
        $d = $m->d;
        $nome = $e($d['nome']);
        $contatos = [];
        if ($d['temWhatsapp']) {
            $contatos[] = 'WhatsApp <a href="' . $e($d['whatsappLink']) . '" target="_blank" rel="noopener" data-ev="whatsapp" data-pos="privacidade">' . $e($d['whatsapp']) . '</a>';
        }
        if ($d['temEmail']) {
            $contatos[] = 'e-mail <a href="' . $e($d['emailLink']) . '">' . $e($d['email']) . '</a>';
        }
        if ($d['temTelefone']) {
            $contatos[] = 'telefone <a href="' . $e($d['telefoneLink']) . '" data-ev="telefone">' . $e($d['telefone']) . '</a>';
        }
        $canais = $contatos === [] ? 'pelos canais de contato informados no site'
            : 'pelo ' . (count($contatos) === 1 ? $contatos[0] : implode(', ', array_slice($contatos, 0, -1)) . ' ou pelo ' . $contatos[count($contatos) - 1]);
        $local = $d['endereco'] !== '' ? $e($d['endereco']) : $e($d['cidadeUf']);
        $meses = max(1, $retencaoMeses);

        $h = '<p class="rk-eyebrow">' . $nome . '</p>';
        $h .= '<h1 class="rk-h1">Política de privacidade</h1>';
        $h .= '<p class="rk-lead">Esta página explica, em linguagem simples, quais dados pessoais este site coleta, para quê e como você pode exercer os seus direitos, conforme a Lei Geral de Proteção de Dados (Lei nº 13.709/2018, LGPD).</p>';
        $h .= '<p class="rk-pagina__data">Atualizada em ' . $e(self::dataExtenso($data)) . '.</p>';

        $h .= '<h2 class="rk-h3">Quem é o responsável pelos dados</h2>';
        $h .= '<p class="rk-p">O controlador dos dados é <strong>' . $nome . '</strong>' . ($local !== '' ? ', ' . $local : '')
            . '. Para falar sobre privacidade, entre em contato ' . $canais . '.</p>';

        $h .= '<h2 class="rk-h3">Quais dados coletamos e para quê</h2>';
        $h .= '<ul><li><strong>Formulário de contato:</strong> nome, telefone ou WhatsApp e, se você quiser, e-mail e mensagem. Usamos esses dados só para responder ao seu contato e prestar o atendimento que você pediu (procedimentos preliminares a pedido do titular, art. 7º, V, da LGPD).</li>'
            . '<li><strong>Proteção contra spam:</strong> o endereço IP de quem envia o formulário é transformado num código irreversível (hash), que serve só para limitar envios automáticos. O IP em si não é guardado.</li>'
            . '<li><strong>Origem da visita:</strong> se você chegou por um anúncio ou link de campanha, registramos junto com o contato a página de entrada e os parâmetros da campanha (como utm_source e gclid), para sabermos quais anúncios funcionam. Essa informação fica guardada só na aba do seu navegador (sessionStorage) até ser enviada com o formulário.</li></ul>';

        $h .= '<h2 class="rk-h3">Por quanto tempo guardamos</h2>';
        $h .= '<p class="rk-p">Os contatos recebidos pelo formulário são guardados por até ' . $meses . ' ' . ($meses === 1 ? 'mês' : 'meses')
            . ' e depois excluídos automaticamente. Você pode pedir a exclusão antes disso a qualquer momento.</p>';

        $h .= '<h2 class="rk-h3">Com quem compartilhamos</h2>';
        $h .= '<p class="rk-p">Não vendemos nem cedemos os seus dados. Eles ficam armazenados no servidor da empresa que hospeda e mantém este site, que atua como operadora e só os trata para essa finalidade. Um aviso do seu contato é enviado por e-mail à equipe de ' . $nome . ' para que possamos responder.</p>';

        $h .= '<h2 class="rk-h3">Cookies e rastreadores</h2>';
        if ($rastreadores === []) {
            $h .= '<p class="rk-p">Este site não usa cookies de publicidade nem de estatística. As fontes e as imagens são servidas pelo próprio site, sem repassar o seu endereço IP a terceiros.</p>';
        } else {
            $h .= '<p class="rk-p">Com a sua permissão, este site usa as ferramentas abaixo para medir as visitas e o resultado dos anúncios. Elas só são carregadas se você clicar em <strong>Aceitar</strong> na faixa de cookies; se recusar, nada é carregado.</p><ul>';
            foreach ($rastreadores as $r) {
                $h .= '<li>' . $e($r) . '</li>';
            }
            $h .= '</ul><p class="rk-p">A sua escolha fica guardada neste navegador. <button type="button" class="rk-btn rk-btn--sec" data-consentimento>Alterar a minha escolha sobre cookies</button></p>';
        }
        $h .= '<p class="rk-p">O mapa do Google só é carregado se você clicar para vê-lo. Os botões de WhatsApp levam ao aplicativo da Meta, que tem política de privacidade própria.</p>';

        $h .= '<h2 class="rk-h3">Os seus direitos</h2>';
        $h .= '<p class="rk-p">Pela LGPD (art. 18), você pode pedir a qualquer momento: confirmação de que tratamos os seus dados; acesso a eles; correção de dados incompletos ou desatualizados; anonimização, bloqueio ou eliminação de dados desnecessários; portabilidade; informação sobre com quem compartilhamos; e revogação do consentimento.</p>';
        $h .= '<p class="rk-p">Para exercer esses direitos, fale com a gente ' . $canais . '. Respondemos em até 15 dias. Se não ficar satisfeito, você também pode reclamar à Autoridade Nacional de Proteção de Dados (ANPD).</p>';
        $botao = self::botaoWhatsapp($m, 'privacidade', 'Falar no WhatsApp');
        $h .= '<div class="rk-acoes">' . $botao . '<a class="rk-btn rk-btn--sec" href="/">Voltar ao site</a></div>';
        return self::secao($h);
    }

    /** Página de agradecimento (destino do formulário sem JavaScript; URL de conversão). */
    public static function obrigado(Montagem $m): string
    {
        $h = '<p class="rk-eyebrow">' . Texto::escapeHtml($m->d['nome']) . '</p>';
        $h .= '<h1 class="rk-h1">Mensagem enviada!</h1>';
        $h .= '<p class="rk-lead">Recebemos o seu contato e retornamos em breve. Se preferir, continue a conversa agora pelo WhatsApp.</p>';
        $h .= '<div class="rk-acoes">' . self::botaoWhatsapp($m, 'obrigado', 'Continuar pelo WhatsApp') . '<a class="rk-btn rk-btn--sec" href="/">Voltar ao site</a></div>';
        return self::secao($h);
    }

    /** Página 404 do site. */
    public static function naoEncontrada(Montagem $m): string
    {
        $h = '<p class="rk-eyebrow">Erro 404</p>';
        $h .= '<h1 class="rk-h1">Página não encontrada</h1>';
        $h .= '<p class="rk-lead">O endereço que você abriu não existe ou mudou. Volte para a página inicial ou fale com a gente.</p>';
        $h .= '<div class="rk-acoes"><a class="rk-btn' . ($m->d['temWhatsapp'] ? ' rk-btn--sec' : '') . '" href="/">Ir para a página inicial</a>'
            . self::botaoWhatsapp($m, '404') . '</div>';
        return self::secao($h);
    }
}
