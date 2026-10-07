<?php

declare(strict_types=1);

namespace Rankly\Testes\Api;

use Rankly\Api\Api;
use Rankly\Aplicacao;
use Rankly\Http\Requisicao;
use Rankly\Http\Resposta;

/**
 * Cliente da API em processo (sem servidor): guarda cookies e o token CSRF como o editor.
 */
final class ClienteApi
{
    public array $cookies = [];
    public ?string $csrf = null;
    public string $ip = '203.0.113.10';
    public bool $enviarCsrf = true;
    public readonly Api $api;

    public function __construct(public readonly Aplicacao $app)
    {
        $this->api = new Api($app);
    }

    public function get(string $caminho, array $cabecalhos = []): Resposta
    {
        return $this->req('GET', $caminho, null, $cabecalhos);
    }

    public function post(string $caminho, mixed $json = null, array $cabecalhos = []): Resposta
    {
        return $this->req('POST', $caminho, $json, $cabecalhos);
    }

    public function put(string $caminho, mixed $json = null): Resposta
    {
        return $this->req('PUT', $caminho, $json);
    }

    public function patch(string $caminho, mixed $json = null): Resposta
    {
        return $this->req('PATCH', $caminho, $json);
    }

    public function delete(string $caminho): Resposta
    {
        return $this->req('DELETE', $caminho);
    }

    /** Requisição com corpo JSON (ou formulário, se $form). */
    public function req(string $metodo, string $caminho, mixed $json = null, array $cabecalhos = [], ?array $form = null, array $arquivos = []): Resposta
    {
        $query = [];
        if (str_contains($caminho, '?')) {
            [$caminho, $qs] = explode('?', $caminho, 2);
            parse_str($qs, $query);
        }
        $corpo = '';
        if ($json !== null) {
            $corpo = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $cabecalhos += ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        }
        if ($form !== null && $arquivos === []) {
            $corpo = http_build_query($form);
            $cabecalhos += ['Content-Type' => 'application/x-www-form-urlencoded'];
        }
        if ($this->enviarCsrf && $this->csrf !== null && !in_array($metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $cabecalhos += ['X-CSRF-Token' => $this->csrf];
        }
        $req = new Requisicao($metodo, $caminho, $query, $cabecalhos, $corpo, $this->cookies, $form ?? [], $arquivos, ['REMOTE_ADDR' => $this->ip]);
        $r = $this->api->processar($req);
        $this->guardarCookies($r);
        return $r;
    }

    /** POST multipart simulado (arquivo já no disco). */
    public function enviarArquivo(string $caminho, array $form, string $arquivo, string $campo = 'arquivo', string $nome = 'foto.jpg'): Resposta
    {
        // Cópia temporária: o processamento não deve depender do arquivo de origem.
        $tmp = tempnam(sys_get_temp_dir(), 'rkup');
        copy($arquivo, $tmp);
        try {
            return $this->req('POST', $caminho, null, ['Accept' => 'application/json'], $form, [
                $campo => ['name' => $nome, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)],
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    public function login(string $email, string $senha): Resposta
    {
        $r = $this->post('/api/auth/login', ['email' => $email, 'senha' => $senha]);
        $dados = $r->dados();
        if ($r->status === 200 && is_array($dados) && isset($dados['csrf'])) {
            $this->csrf = $dados['csrf'];
        }
        return $r;
    }

    private function guardarCookies(Resposta $r): void
    {
        foreach ($r->cookies() as $linha) {
            [$par] = explode(';', $linha, 2);
            [$nome, $valor] = explode('=', $par, 2);
            if (preg_match('/Max-Age=0(;|$)/', $linha)) {
                unset($this->cookies[$nome]);
            } else {
                $this->cookies[$nome] = $valor;
            }
        }
    }
}
