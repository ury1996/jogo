<?php

declare(strict_types=1);

namespace Rankly\Http;

/**
 * Roteador simples: método + padrão com parâmetros ("/api/sites/{id}" ou "{id:\d+}").
 * A ação recebe (Requisicao, array $parametros) e devolve Resposta.
 */
final class Router
{
    /** @var list<array{metodo: string, padrao: string, regex: string, acao: callable, opcoes: array}> */
    private array $rotas = [];

    public function adicionar(string $metodo, string $padrao, callable $acao, array $opcoes = []): void
    {
        $this->rotas[] = [
            'metodo' => strtoupper($metodo),
            'padrao' => $padrao,
            'regex' => self::compilar($padrao),
            'acao' => $acao,
            'opcoes' => $opcoes,
        ];
    }

    public function get(string $padrao, callable $acao, array $opcoes = []): void
    {
        $this->adicionar('GET', $padrao, $acao, $opcoes);
    }

    public function post(string $padrao, callable $acao, array $opcoes = []): void
    {
        $this->adicionar('POST', $padrao, $acao, $opcoes);
    }

    public function put(string $padrao, callable $acao, array $opcoes = []): void
    {
        $this->adicionar('PUT', $padrao, $acao, $opcoes);
    }

    public function patch(string $padrao, callable $acao, array $opcoes = []): void
    {
        $this->adicionar('PATCH', $padrao, $acao, $opcoes);
    }

    public function delete(string $padrao, callable $acao, array $opcoes = []): void
    {
        $this->adicionar('DELETE', $padrao, $acao, $opcoes);
    }

    /** "/api/sites/{id:\d+}/leads" → regex ancorada com grupos nomeados. */
    public static function compilar(string $padrao): string
    {
        $regex = '';
        $pos = 0;
        if (preg_match_all('/\{([a-z_][a-z0-9_]*)(?::([^{}]+))?\}/i', $padrao, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $par) {
                $regex .= preg_quote(substr($padrao, $pos, $par[0][1] - $pos), '#');
                $regex .= '(?P<' . $par[1][0] . '>' . (isset($par[2]) ? $par[2][0] : '[^/]+') . ')';
                $pos = $par[0][1] + strlen($par[0][0]);
            }
        }
        $regex .= preg_quote(substr($padrao, $pos), '#');
        return '#^' . $regex . '$#D';
    }

    /**
     * Rota para método + caminho.
     *
     * @return array{acao: callable, parametros: array<string,string>, opcoes: array, padrao: string}
     * @throws ErroHttp 404 (caminho desconhecido) ou 405 (método não permitido)
     */
    public function encontrar(string $metodo, string $caminho): array
    {
        $metodo = strtoupper($metodo) === 'HEAD' ? 'GET' : strtoupper($metodo);
        $permitidos = [];
        foreach ($this->rotas as $rota) {
            if (!preg_match($rota['regex'], $caminho, $m)) {
                continue;
            }
            if ($rota['metodo'] !== $metodo) {
                $permitidos[] = $rota['metodo'];
                continue;
            }
            $parametros = [];
            foreach ($m as $chave => $valor) {
                if (is_string($chave)) {
                    $parametros[$chave] = $valor;
                }
            }
            return ['acao' => $rota['acao'], 'parametros' => $parametros, 'opcoes' => $rota['opcoes'], 'padrao' => $rota['padrao']];
        }
        if ($permitidos !== []) {
            throw new ErroHttp(405, 'metodo_nao_permitido', 'Método não permitido para este endereço.', [], [
                'Allow' => implode(', ', array_unique($permitidos)),
            ]);
        }
        throw ErroHttp::naoEncontrado('Endereço da API não encontrado.');
    }

    /** Encontra e executa a rota. */
    public function despachar(Requisicao $req): Resposta
    {
        $rota = $this->encontrar($req->metodo, $req->caminho);
        return ($rota['acao'])($req, $rota['parametros']);
    }
}
