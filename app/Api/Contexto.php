<?php

declare(strict_types=1);

namespace Rankly\Api;

use Rankly\Aplicacao;
use Rankly\Http\ErroHttp;
use Rankly\Http\Requisicao;
use Rankly\Lib\Sessao;
use Rankly\Lib\Sites;
use Rankly\Lib\Usuarios;

/**
 * Contexto de uma requisição da API: aplicação, requisição, sessão e usuário logado.
 */
final class Contexto
{
    private ?array $usuario = null;
    private bool $usuarioCarregado = false;

    public function __construct(
        public readonly Aplicacao $app,
        public readonly Requisicao $req,
        public readonly Sessao $sessao,
    ) {
    }

    /** Marca da credencial guardada na sessão: muda quando a senha muda ou a conta é desativada. */
    public static function marcaCredencial(array $usuario): string
    {
        return substr(hash('sha256', (string) $usuario['senha_hash'] . '|' . (int) $usuario['ativo']), 0, 24);
    }

    /** Usuário da sessão (ativo e com a mesma senha de quando entrou) ou null. */
    public function usuario(): ?array
    {
        if ($this->usuarioCarregado) {
            return $this->usuario;
        }
        $this->usuarioCarregado = true;
        $id = $this->sessao->get('usuario_id');
        if (!is_int($id)) {
            return null;
        }
        $u = Usuarios::porId($this->app, $id);
        if ($u === null || (int) $u['ativo'] !== 1 || !hash_equals((string) $this->sessao->get('marca', ''), self::marcaCredencial($u))) {
            // Conta removida/desativada ou senha trocada: a sessão deixa de valer.
            $this->sessao->destruir();
            return null;
        }
        return $this->usuario = $u;
    }

    /** Define o usuário logado nesta requisição (login). */
    public function definirUsuario(?array $u): void
    {
        $this->usuario = $u;
        $this->usuarioCarregado = true;
    }

    /** @throws ErroHttp 401 */
    public function exigirUsuario(): array
    {
        return $this->usuario() ?? throw ErroHttp::naoAutenticado();
    }

    /** Só admin/equipe (cliente não cria, duplica nem arquiva sites). @throws ErroHttp 401/403 */
    public function exigirEquipe(): array
    {
        $u = $this->exigirUsuario();
        if (!Usuarios::ehEquipe($u)) {
            throw ErroHttp::proibido('Esta ação é exclusiva da equipe.');
        }
        return $u;
    }

    /**
     * Site com permissão de acesso do usuário logado. Sem permissão também responde 404
     * (não revela a existência de sites de terceiros).
     *
     * @throws ErroHttp 401/404
     */
    public function exigirSite(int $id): array
    {
        $u = $this->exigirUsuario();
        $site = Sites::porId($this->app, $id);
        if ($site === null || !Sites::podeAcessar($this->app, $u, $id)) {
            throw ErroHttp::naoEncontrado('Site não encontrado.');
        }
        return $site;
    }

    public function usuarioId(): ?int
    {
        $u = $this->usuario();
        return $u !== null ? (int) $u['id'] : null;
    }

    /** Corpo como mapa (JSON ou formulário). */
    public function dados(): array
    {
        return $this->req->dados();
    }

    /** Parâmetro numérico de rota → int (404 se fora do intervalo). */
    public static function inteiro(string $v): int
    {
        if (!ctype_digit($v) || strlen($v) > 10 || (int) $v < 1 || (int) $v > 2147483647) {
            throw ErroHttp::naoEncontrado();
        }
        return (int) $v;
    }
}
