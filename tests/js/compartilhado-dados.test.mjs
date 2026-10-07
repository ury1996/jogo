import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  formatarCep, formatarEndereco, formatarHora, formatarHorarios, formatarRegistro, formatarTelefone, horariosTexto, inicial,
  linhasEndereco, linkTelefone, linkWhatsapp, soDigitos, urlRede, validarEmail, validarWhatsapp,
} from '../../public_html/editor/js/compartilhado/dados.mjs';

test('telefone: dígitos, máscara e links', () => {
  assert.equal(soDigitos('(11) 98765-4321'), '11987654321');
  assert.equal(formatarTelefone('11987654321'), '(11) 98765-4321');
  assert.equal(formatarTelefone('+55 11 98765-4321'), '(11) 98765-4321');
  assert.equal(formatarTelefone('1134567890'), '(11) 3456-7890');
  assert.equal(formatarTelefone('011 3456-7890'), '(11) 3456-7890', 'tira o 0 de operadora');
  assert.equal(formatarTelefone('08001234567'), '0800 123 4567');
  assert.equal(formatarTelefone('987654321'), '98765-4321');
  assert.equal(formatarTelefone(' (11) 9876 '), '(11) 9876', 'incompleto fica como digitado');
  assert.equal(linkTelefone('(11) 3456-7890'), 'tel:+551134567890');
  assert.equal(linkTelefone('0800 123 4567'), 'tel:08001234567');
  assert.equal(linkTelefone(''), '');
});

test('validarWhatsapp: 10/11 dígitos, DDD válido, celular com 9, fixo 2–5', () => {
  for (const ok of ['(11) 98765-4321', '+55 (11) 98765-4321', '(11) 3456-7890', '(55) 99999-9999', '(19) 99876-5432']) assert.equal(validarWhatsapp(ok), true, ok);
  for (const ruim of ['(20) 98765-4321', '(11) 8765-4321', '(11) 88765-4321', '98765-4321', '', '0800 123 4567', '(10) 3456-7890']) assert.equal(validarWhatsapp(ruim), false, ruim);
});

test('linkWhatsapp = https://wa.me/55{dígitos}?text={codificarUri(mensagem)}', () => {
  assert.equal(
    linkWhatsapp('(11) 98765-4321', "Olá! Quero (agendar) 'já' & ver"),
    "https://wa.me/5511987654321?text=Ol%C3%A1!%20Quero%20(agendar)%20'j%C3%A1'%20%26%20ver",
  );
  assert.equal(linkWhatsapp('+55 11 98765-4321', ''), 'https://wa.me/5511987654321');
  assert.equal(linkWhatsapp('', 'oi'), '');
});

test('endereço: "Rua X, 123 - Sala 4 - Bairro, Cidade - UF, CEP 00000-000"', () => {
  const dados = { cidade: 'Jundiaí', uf: 'sp', endereco: { cep: '13201000', logradouro: 'Rua X', numero: '123', complemento: 'Sala 4', bairro: 'Centro' } };
  assert.equal(formatarEndereco(dados), 'Rua X, 123 - Sala 4 - Centro, Jundiaí - SP, CEP 13201-000');
  assert.deepEqual(linhasEndereco(dados), ['Rua X, 123 - Sala 4', 'Centro, Jundiaí - SP, CEP 13201-000']);
  assert.equal(formatarEndereco({ cidade: 'Jundiaí', endereco: { logradouro: 'Rua X' } }), 'Rua X - Jundiaí');
  assert.equal(formatarEndereco({ uf: 'SP', endereco: { logradouro: 'Av. Brasil', numero: 's/n', cep: '123' } }), 'Av. Brasil, s/n - SP, CEP 123');
  assert.equal(formatarEndereco({ cidade: 'Campinas', endereco: { bairro: 'Cambuí' } }), '', 'sem logradouro não é endereço');
  assert.equal(formatarCep(' 12.245-000 '), '12245-000');
});

test('horários agrupam dias consecutivos iguais e mostram minutos só quando ≠ 00', () => {
  const semana = { seg: ['08:00', '18:00'], ter: ['08:00', '18:00'], qua: ['08:00', '18:00'], qui: ['08:00', '18:00'], sex: ['08:00', '18:00'], sab: ['08:30', '12:00'], dom: null };
  const h = formatarHorarios(semana);
  assert.deepEqual(h, [{ dias: 'Seg a Sex', horas: '8h às 18h' }, { dias: 'Sáb', horas: '8h30 às 12h' }, { dias: 'Dom', horas: 'Fechado' }]);
  assert.equal(horariosTexto(h), 'Seg a Sex: 8h às 18h · Sáb: 8h30 às 12h · Dom: Fechado');
  assert.deepEqual(formatarHorarios({ sab: ['08:00', '12:00'], dom: ['08:00', '12:00'] }), [{ dias: 'Sáb e Dom', horas: '8h às 12h' }]);
  assert.deepEqual(formatarHorarios({ seg: ['08:00', '18:00'], qua: ['08:00', '18:00'] }), [{ dias: 'Seg', horas: '8h às 18h' }, { dias: 'Qua', horas: '8h às 18h' }], 'não consecutivos');
  assert.deepEqual(formatarHorarios({ seg: ['08:00', '12:00', '14:00', '18:00'] }), [{ dias: 'Seg', horas: '8h às 12h e 14h às 18h' }]);
  assert.deepEqual(formatarHorarios({ sab: null, dom: null }), [], 'tudo fechado = não informado');
  assert.deepEqual(formatarHorarios({}), []);
  assert.deepEqual(formatarHorarios([]), []);
  assert.equal(formatarHora('09:05'), '9h05');
  assert.equal(formatarHora('25:00'), '');
});

test('registro conforme especialidade.rotuloRegistro', () => {
  const dados = { uf: 'SP', registro: { numero: '12345', uf: '', responsavel: 'Dra. X' } };
  assert.equal(formatarRegistro(dados, { rotuloRegistro: 'CRO' }), 'Responsável técnico: Dra. X · CRO-SP 12345');
  assert.equal(formatarRegistro({ uf: 'sp', registro: { numero: '123.456' } }, { rotuloRegistro: 'OAB' }), 'OAB/SP 123.456');
  assert.equal(formatarRegistro({ registro: { numero: '1', uf: 'rj' } }, { conselho: 'CRC' }), 'CRC-RJ 1');
  assert.equal(formatarRegistro({ registro: { numero: '1' } }, null), 'Registro 1');
  assert.equal(formatarRegistro({ registro: { numero: '1', responsavel: 'Eng. Y' } }, { rotuloRegistro: 'CREA', rotuloResponsavel: 'Engenheiro' }), 'Engenheiro: Eng. Y · CREA 1');
  assert.equal(formatarRegistro({ registro: { numero: '' , responsavel: 'Z' } }, { rotuloRegistro: 'CRO' }), '');
});

test('inicial, e-mail e redes', () => {
  assert.equal(inicial('clínica'), 'C');
  assert.equal(inicial('  9 vidas'), '9');
  assert.equal(inicial('¿Ética?'), 'É');
  assert.equal(inicial('—'), '');
  assert.equal(validarEmail('contato@clinica.com.br'), true);
  assert.equal(validarEmail('a b@c.com'), false);
  assert.equal(validarEmail('x@y'), false);
  assert.equal(urlRede('instagram', '@clinica.teste'), 'https://www.instagram.com/clinica.teste');
  assert.equal(urlRede('facebook', 'facebook.com/clinica'), 'https://facebook.com/clinica');
  assert.equal(urlRede('linkedin', 'http://linkedin.com/in/x'), 'https://linkedin.com/in/x');
  assert.equal(urlRede('youtube', 'canal'), 'https://www.youtube.com/@canal');
  assert.equal(urlRede('google', 'perfil'), '', 'Google precisa de URL');
  assert.equal(urlRede('instagram', 'javascript:alert(1)'), '');
  assert.equal(urlRede('instagram', 'https://x.com/"><script>'), '');
});
