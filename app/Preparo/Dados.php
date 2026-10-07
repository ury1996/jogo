<?php

declare(strict_types=1);

namespace Rankly\Preparo;

/**
 * Dados estruturados do negócio [M6]: telefone, WhatsApp, endereço, horários, registro, redes.
 * PARIDADE OBRIGATÓRIA com public_html/editor/js/compartilhado/dados.mjs.
 */
final class Dados
{
    /** DDDs brasileiros válidos (Anatel). */
    private const DDDS = [
        11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22, 24, 27, 28, 31, 32, 33, 34, 35, 37, 38,
        41, 42, 43, 44, 45, 46, 47, 48, 49, 51, 53, 54, 55, 61, 62, 63, 64, 65, 66, 67, 68, 69,
        71, 73, 74, 75, 77, 79, 81, 82, 83, 84, 85, 86, 87, 88, 89, 91, 92, 93, 94, 95, 96, 97, 98, 99,
    ];
    private const RE_NUMERO_ESPECIAL = '/^0[3589]00/'; // 0800, 0300, 0500, 0900
    private const DIAS = [['seg', 'Seg'], ['ter', 'Ter'], ['qua', 'Qua'], ['qui', 'Qui'], ['sex', 'Sex'], ['sab', 'Sáb'], ['dom', 'Dom']];
    private const ROTULOS_REDES = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'google' => 'Google'];
    private const PERFIS_REDES = [
        'instagram' => 'https://www.instagram.com/',
        'facebook' => 'https://www.facebook.com/',
        'linkedin' => 'https://www.linkedin.com/in/',
        'youtube' => 'https://www.youtube.com/@',
    ];

    public static function soDigitos(mixed $s): string
    {
        return preg_replace('/[^0-9]+/', '', Texto::textoDe($s)) ?? '';
    }

    /** Dígitos no formato nacional: tira +55 (12–13 dígitos) e o 0 de operadora. */
    public static function digitosNacionais(mixed $s): string
    {
        $d = self::soDigitos($s);
        $n = strlen($d);
        if (($n === 12 || $n === 13) && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        } elseif (($n === 11 || $n === 12) && str_starts_with($d, '0') && !preg_match(self::RE_NUMERO_ESPECIAL, $d)) {
            $d = substr($d, 1);
        }
        return $d;
    }

    /** 10 ou 11 dígitos, DDD válido; celular (11) começa com 9; fixo (10) com 2–5. */
    public static function validarWhatsapp(mixed $s): bool
    {
        $d = self::digitosNacionais($s);
        $n = strlen($d);
        if ($n !== 10 && $n !== 11) {
            return false;
        }
        if (!in_array((int) substr($d, 0, 2), self::DDDS, true)) {
            return false;
        }
        if ($n === 11) {
            return $d[2] === '9';
        }
        return $d[2] >= '2' && $d[2] <= '5';
    }

    /** "(11) 98765-4321", "(11) 3456-7890", "0800 123 4567"; incompleto → como digitado. */
    public static function formatarTelefone(mixed $s): string
    {
        $d = self::digitosNacionais($s);
        $n = strlen($d);
        if ($n === 11 && preg_match(self::RE_NUMERO_ESPECIAL, $d)) {
            return substr($d, 0, 4) . ' ' . substr($d, 4, 3) . ' ' . substr($d, 7);
        }
        return match ($n) {
            11 => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7),
            10 => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6),
            9 => substr($d, 0, 5) . '-' . substr($d, 5),
            8 => substr($d, 0, 4) . '-' . substr($d, 4),
            default => Texto::colapsarEspacos($s),
        };
    }

    /** https://wa.me/55{dígitos}?text={mensagem codificada}; sem dígitos → "". */
    public static function linkWhatsapp(mixed $numero, mixed $msg): string
    {
        $d = self::digitosNacionais($numero);
        if ($d === '') {
            return '';
        }
        $mensagem = Texto::textoDe($msg);
        return 'https://wa.me/55' . $d . ($mensagem !== '' ? '?text=' . Texto::codificarUri($mensagem) : '');
    }

    /** tel:+55{dígitos} (0800 sem +55); sem dígitos → "". */
    public static function linkTelefone(mixed $s): string
    {
        $d = self::digitosNacionais($s);
        if ($d === '') {
            return '';
        }
        if (preg_match(self::RE_NUMERO_ESPECIAL, $d)) {
            return 'tel:' . $d;
        }
        $n = strlen($d);
        return $n === 10 || $n === 11 ? 'tel:+55' . $d : 'tel:' . $d;
    }

    public static function validarEmail(mixed $s): bool
    {
        $c = Texto::CLASSE_ESPACOS;
        $parte = '[^' . $c . '@<>"\']+';
        return preg_match('/^' . $parte . '@' . $parte . '\.' . $parte . '$/uD', Texto::colapsarEspacos($s)) === 1;
    }

    /** CEP com 8 dígitos → "00000-000"; outro formato → como digitado. */
    public static function formatarCep(mixed $s): string
    {
        $d = self::soDigitos($s);
        return strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : Texto::colapsarEspacos($s);
    }

    /**
     * Endereço em duas linhas: ["Rua X, 123 - Sala 4", "Bairro, Cidade - UF, CEP 00000-000"].
     * Sem logradouro → [] (cidade sozinha não é endereço). Cidade/UF vêm de dados.cidade/uf.
     *
     * @return list<string>
     */
    public static function linhasEndereco(mixed $dados): array
    {
        $d = Texto::comoMapa($dados);
        $e = Texto::comoMapa(Texto::pegar($d, 'endereco'));
        $logradouro = Texto::colapsarEspacos(Texto::pegar($e, 'logradouro'));
        if ($logradouro === '') {
            return [];
        }
        $numero = Texto::colapsarEspacos(Texto::pegar($e, 'numero'));
        $complemento = Texto::colapsarEspacos(Texto::pegar($e, 'complemento'));
        $bairro = Texto::colapsarEspacos(Texto::pegar($e, 'bairro'));
        $cidade = Texto::colapsarEspacos(Texto::pegar($d, 'cidade'));
        $uf = mb_strtoupper(Texto::colapsarEspacos(Texto::pegar($d, 'uf')), 'UTF-8');
        $cep = self::formatarCep(Texto::pegar($e, 'cep'));
        $linha1 = $logradouro;
        if ($numero !== '') {
            $linha1 .= ', ' . $numero;
        }
        if ($complemento !== '') {
            $linha1 .= ' - ' . $complemento;
        }
        $cidadeUf = $cidade !== '' && $uf !== '' ? $cidade . ' - ' . $uf : $cidade . $uf;
        $linha2 = $bairro;
        if ($cidadeUf !== '') {
            $linha2 = $linha2 !== '' ? $linha2 . ', ' . $cidadeUf : $cidadeUf;
        }
        if ($cep !== '') {
            $linha2 = $linha2 !== '' ? $linha2 . ', CEP ' . $cep : 'CEP ' . $cep;
        }
        return $linha2 !== '' ? [$linha1, $linha2] : [$linha1];
    }

    /** "Rua X, 123 - Sala 4 - Bairro, Cidade - UF, CEP 00000-000" (ou ""). */
    public static function formatarEndereco(mixed $dados): string
    {
        return implode(' - ', self::linhasEndereco($dados));
    }

    /** "08:00" → "8h"; "08:30" → "8h30"; inválido → "". */
    public static function formatarHora(mixed $s): string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/D', Texto::colapsarEspacos($s), $m)) {
            return '';
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 24 || $min > 59) {
            return '';
        }
        return $min === 0 ? $h . 'h' : $h . 'h' . $m[2];
    }

    private static function formatarIntervalos(array $v): string
    {
        $partes = [];
        for ($i = 0; $i + 1 < count($v); $i += 2) {
            $abre = self::formatarHora($v[$i]);
            $fecha = self::formatarHora($v[$i + 1]);
            if ($abre !== '' && $fecha !== '') {
                $partes[] = $abre . ' às ' . $fecha;
            }
        }
        return implode(' e ', $partes);
    }

    /**
     * Horários agrupando dias consecutivos iguais: [{dias:"Seg a Sex", horas:"8h às 18h"},
     * {dias:"Sáb", horas:"8h às 12h"}, {dias:"Dom", horas:"Fechado"}]. Dia ausente = não
     * informado (fica de fora); null = fechado. Nenhum dia aberto → [].
     *
     * @return list<array{dias: string, horas: string}>
     */
    public static function formatarHorarios(mixed $horarios): array
    {
        $h = Texto::comoMapa($horarios);
        $grupos = [];
        foreach (self::DIAS as $i => [$chave, $nome]) {
            if (!Texto::temChave($h, $chave)) {
                continue;
            }
            $v = $h[$chave];
            if ($v === null || $v === false) {
                $horas = 'Fechado';
            } elseif (is_array($v) && array_is_list($v)) {
                $horas = self::formatarIntervalos($v);
            } else {
                continue;
            }
            if ($horas === '') {
                continue;
            }
            $ultimo = count($grupos) - 1;
            if ($ultimo >= 0 && $grupos[$ultimo]['horas'] === $horas && $grupos[$ultimo]['fim'] === $i - 1) {
                $grupos[$ultimo]['fim'] = $i;
                $grupos[$ultimo]['nomeFim'] = $nome;
                $grupos[$ultimo]['n']++;
            } else {
                $grupos[] = ['inicio' => $i, 'fim' => $i, 'nomeInicio' => $nome, 'nomeFim' => $nome, 'horas' => $horas, 'n' => 1];
            }
        }
        $algumAberto = false;
        foreach ($grupos as $g) {
            if ($g['horas'] !== 'Fechado') {
                $algumAberto = true;
            }
        }
        if (!$algumAberto) {
            return [];
        }
        return array_map(static fn (array $g): array => [
            'dias' => $g['n'] === 1 ? $g['nomeInicio'] : $g['nomeInicio'] . ($g['n'] === 2 ? ' e ' : ' a ') . $g['nomeFim'],
            'horas' => $g['horas'],
        ], $grupos);
    }

    /** "Seg a Sex: 8h às 18h · Sáb: 8h às 12h · Dom: Fechado". */
    public static function horariosTexto(array $lista): string
    {
        return implode(' · ', array_map(
            static fn ($g): string => Texto::textoDe(Texto::pegar($g, 'dias')) . ': ' . Texto::textoDe(Texto::pegar($g, 'horas')),
            Texto::comoLista($lista),
        ));
    }

    /**
     * "Responsável técnico: Dra. X · CRO-SP 12345" conforme especialidade.rotuloRegistro
     * (OAB usa barra: "OAB/SP 123.456"). Sem número → "".
     */
    public static function formatarRegistro(mixed $dados, mixed $especialidade): string
    {
        $d = Texto::comoMapa($dados);
        $r = Texto::comoMapa(Texto::pegar($d, 'registro'));
        $numero = Texto::colapsarEspacos(Texto::pegar($r, 'numero'));
        if ($numero === '') {
            return '';
        }
        $esp = Texto::comoMapa($especialidade);
        $rotulo = Texto::colapsarEspacos(Texto::pegar($esp, 'rotuloRegistro'));
        if ($rotulo === '') {
            $rotulo = Texto::colapsarEspacos(Texto::pegar($esp, 'conselho'));
        }
        if ($rotulo === '') {
            $rotulo = 'Registro';
        }
        $uf = Texto::colapsarEspacos(Texto::pegar($r, 'uf'));
        if ($uf === '') {
            $uf = Texto::colapsarEspacos(Texto::pegar($d, 'uf'));
        }
        $uf = mb_strtoupper($uf, 'UTF-8');
        $separador = $rotulo === 'OAB' ? '/' : '-';
        $registro = $uf !== '' ? $rotulo . $separador . $uf . ' ' . $numero : $rotulo . ' ' . $numero;
        $responsavel = Texto::colapsarEspacos(Texto::pegar($r, 'responsavel'));
        if ($responsavel === '') {
            return $registro;
        }
        $rotuloResponsavel = Texto::colapsarEspacos(Texto::pegar($esp, 'rotuloResponsavel'));
        if ($rotuloResponsavel === '') {
            $rotuloResponsavel = 'Responsável técnico';
        }
        return $rotuloResponsavel . ': ' . $responsavel . ' · ' . $registro;
    }

    /** Primeira letra ou número do nome, em maiúscula (ou ""). */
    public static function inicial(mixed $nome): string
    {
        if (!preg_match('/[\p{L}\p{N}]/u', Texto::textoDe($nome), $m)) {
            return '';
        }
        return mb_strtoupper($m[0], 'UTF-8');
    }

    /**
     * URL https do perfil (aceita URL, "@usuario", "usuario" ou "dominio.com/…").
     * Só https:// sai daqui (§12.1); o resto vira "".
     */
    public static function urlRede(string $rede, mixed $valor): string
    {
        $v = Texto::colapsarEspacos($valor);
        if ($v === '') {
            return '';
        }
        $url = '';
        if (preg_match('/^https:\/\//i', $v)) {
            $url = 'https://' . substr($v, 8);
        } elseif (preg_match('/^http:\/\//i', $v)) {
            $url = 'https://' . substr($v, 7);
        } elseif (str_contains($v, '/') || preg_match('/^(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)*\.(com|br|net|org|me|gl|be|page|link)$/iD', $v)) {
            $url = 'https://' . $v;
        } else {
            $usuario = str_starts_with($v, '@') ? substr($v, 1) : $v;
            if (preg_match('/^[A-Za-z0-9._-]+$/D', $usuario) && array_key_exists($rede, self::PERFIS_REDES)) {
                $url = self::PERFIS_REDES[$rede] . $usuario;
            }
        }
        return preg_match('/^https:\/\/[^ "\'<>`]+$/D', $url) ? $url : '';
    }

    public static function rotuloRede(string $rede): string
    {
        return self::ROTULOS_REDES[$rede] ?? $rede;
    }
}
