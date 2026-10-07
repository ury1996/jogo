// Fundos alternados automáticos (contrato §5.2).
// PARIDADE OBRIGATÓRIA com app/Preparo/Tons.php.

const FIXOS = { branco: 'branco', 'tom-claro': 'tom', escuro: 'escuro', cor: 'cor' };

/**
 * Converte os tons das opções (na ordem da página, incluindo header e rodapé) nos
 * fundos 'branco' | 'tom' | 'escuro' | 'cor'. Tom desconhecido conta como "claro".
 */
export function calcularFundos(tons) {
  const fundos = [];
  let anterior = 'branco';
  for (const tom of Array.isArray(tons) ? tons : []) {
    const fundo = typeof tom === 'string' && Object.prototype.hasOwnProperty.call(FIXOS, tom)
      ? FIXOS[tom]
      : (anterior === 'branco' ? 'tom' : 'branco');
    fundos.push(fundo);
    anterior = fundo;
  }
  return fundos;
}
