<?php

declare(strict_types=1);

namespace Rankly\Lib\Ia;

/** Um fornecedor de IA que devolve JSON (trocável na configuração 'ia.provedor'). */
interface Provedor
{
    /** Nome curto para registro e para a interface ("gemini", "simulado"). */
    public function nome(): string;

    /**
     * Pede um objeto JSON.
     *
     * @param string $instrucoes regras fixas (papel, tom, conformidade)
     * @param string $pedido     o pedido concreto (dados do negócio e campos a preencher)
     * @param array  $esquema    esquema da resposta (subconjunto OpenAPI: type/properties/required/items)
     * @return array o objeto decodificado
     * @throws ErroIa
     */
    public function gerarJson(string $instrucoes, string $pedido, array $esquema): array;
}
