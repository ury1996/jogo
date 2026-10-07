<?php

declare(strict_types=1);

namespace Rankly\Testes\Gerador;

use Rankly\Aplicacao;
use Rankly\Lib\Midia;
use Rankly\Lib\Sites;
use Rankly\Preparo\Documento;
use Rankly\Testes\Lib\AmbienteTeste;
use Rankly\Testes\Lib\Imagens;

/**
 * Site de exemplo para os testes do gerador: aplicação isolada (SQLite + pasta temporária)
 * com a biblioteca real do projeto e um site completo, pronto para publicar.
 */
final class SiteExemplo
{
    public static function biblioteca(): string
    {
        return dirname(__DIR__, 3) . '/biblioteca';
    }

    /** Aplicação de teste com a biblioteca real. $dir recebe a pasta temporária. */
    public static function app(array $extra = [], ?string &$dir = null): Aplicacao
    {
        return AmbienteTeste::criar($extra + ['dir_biblioteca' => self::biblioteca()], $dir);
    }

    /** Dados completos de um negócio (passam na validação de clínicas/odontologia). */
    public static function dados(): array
    {
        return [
            'nome' => 'Clínica Sorriso Vivo', 'cidade' => 'Jundiaí', 'uf' => 'SP',
            'whatsapp' => '(11) 98765-4321', 'telefone' => '(11) 4521-3080', 'email' => 'contato@sorrisovivo.com.br',
            'endereco' => ['cep' => '13201005', 'logradouro' => 'Rua Barão de Jundiaí', 'numero' => '1100', 'complemento' => 'Sala 4', 'bairro' => 'Centro'],
            'horarios' => [
                'seg' => ['08:00', '18:00'], 'ter' => ['08:00', '18:00'], 'qua' => ['08:00', '18:00'], 'qui' => ['08:00', '18:00'],
                'sex' => ['08:00', '17:00'], 'sab' => ['08:00', '12:00'], 'dom' => null,
            ],
            'registro' => ['numero' => '12345', 'uf' => 'SP', 'responsavel' => 'Dra. Ana Lima'],
            'redes' => ['instagram' => '@sorrisovivo', 'facebook' => 'https://www.facebook.com/sorrisovivo'],
        ];
    }

    /**
     * Documento pronto para publicar: alegações resolvidas (depoimentos reais, números
     * confirmados) e, opcionalmente, outras alterações por cima.
     */
    public static function documento(Aplicacao $app, string $nicho = 'clinicas', string $modelo = 'moderno', array $sobre = []): array
    {
        $doc = Documento::criarDocumento(['nicho' => $nicho, 'modelo' => $modelo, 'dados' => self::dados()], $app->biblioteca());
        $doc['confirmados'] = ['num', 'cli', 'aval'];
        foreach (['1', '2', '3'] as $i) {
            $doc['textos']["dep.$i.t"] = "Atendimento excelente, explicaram cada etapa ($i).";
            $doc['textos']["dep.$i.n"] = "Paciente $i";
            $doc['textos']["dep.$i.c"] = 'Paciente';
        }
        return array_replace_recursive($doc, $sobre);
    }

    /** Usuário + site com o documento (status rascunho). */
    public static function site(Aplicacao $app, ?array $doc = null, string $slug = 'sorrisovivo'): array
    {
        $u = AmbienteTeste::usuario($app);
        return AmbienteTeste::site($app, $slug, $doc ?? self::documento($app), (int) $u['id']);
    }

    /** Envia uma foto (JPEG 2000×1500) e um logo (PNG 600×200) ao site; devolve os ids. */
    public static function midias(Aplicacao $app, int $siteId, string $dir): array
    {
        Imagens::jpeg($dir . '/foto.jpg', 2000, 1500);
        $foto = Midia::receberArquivo($app, $siteId, 'foto', $dir . '/foto.jpg')['midia']['id'];
        Imagens::png($dir . '/logo.png', 600, 200, true);
        $logo = Midia::receberArquivo($app, $siteId, 'logo', $dir . '/logo.png')['midia']['id'];
        return ['foto' => $foto, 'logo' => $logo];
    }

    /** Grava um documento novo no site e devolve a linha atualizada. */
    public static function salvar(Aplicacao $app, int $siteId, array $doc): array
    {
        $app->db()->atualizar('sites', ['documento' => Documento::json($doc)], ['id' => $siteId]);
        return Sites::porId($app, $siteId);
    }
}
