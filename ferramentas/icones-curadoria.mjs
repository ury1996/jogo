// Curadoria dos ícones automáticos (contrato §3.7, PDF cap. 8).
//
// Lida por ferramentas/construir-icones.mjs, que gera biblioteca/icones/icones.json.
//
// Regras do algoritmo (PDF §8.2, compartilhado/icones.mjs ≡ Preparo/Icones.php):
//   - o título é normalizado (minúsculas, sem acento, espaços colapsados);
//   - palavras com até 3 caracteres casam só como palavra inteira; as demais por "contém";
//   - a palavra mais longa vence; empate = ordem desta lista.
// Por isso:
//   - as palavras são RADICAIS sempre que possível ("implant" casa "implante" e "implantes");
//   - quando um termo genérico atrapalha um título específico, cadastre uma expressão mais
//     longa no ícone certo ("consultoria tecnica" vence "consultoria");
//   - a lista vai do mais específico ao mais genérico (os genéricos ficam no fim).
//
// fonte: "phosphor" (arquivo = nome do ícone no @phosphor-icons/core),
//        "healthicons" (arquivo = caminho dentro de public/icons/svg/{outline,filled}),
//        "derivado" (composto a partir de um Healthicons; ver DERIVADOS).

/** @typedef {{id:string, fonte:'phosphor'|'healthicons'|'derivado', arquivo:string, nome:string, categoria:string, palavras:string[]}} Curado */

export const CATEGORIAS = ['geral', 'juridico', 'financas', 'empresas', 'saude'];

const ph = (id, nome, categoria, palavras, arquivo = id) => ({ id, fonte: 'phosphor', arquivo, nome, categoria, palavras });
const hi = (id, arquivo, nome, categoria, palavras) => ({ id, fonte: 'healthicons', arquivo, nome, categoria, palavras });
const de = (id, arquivo, nome, categoria, palavras) => ({ id, fonte: 'derivado', arquivo, nome, categoria, palavras });

/** @type {Curado[]} — ORDEM IMPORTA (desempate do algoritmo). */
export const ICONES = [
  // ---------------------------------------------------------------- saúde: odontologia
  de('braces', 'braces', 'Aparelho dental', 'saude', [
    'ortodont', 'aparelho dental', 'aparelhos dentais', 'aparelho ortodontico', 'aparelho', 'alinhador',
    'alinhadores invisiveis', 'contencao ortodontica',
  ]),
  hi('implant', 'devices/odontology_implant', 'Implante', 'saude', [
    'implant', 'implante dentario', 'implantes dentarios', 'protese sobre implante', 'carga imediata', 'enxerto',
    'enxerto osseo', 'reabilitacao oral',
  ]),
  hi('dental-hygiene', 'devices/dental_hygiene', 'Higiene bucal', 'saude', [
    'higiene bucal', 'higiene oral', 'limpeza dental', 'limpeza e prevencao', 'prevencao e limpeza', 'profilaxia',
    'escovacao', 'fio dental', 'tartaro', 'saude bucal',
  ]),
  hi('x-ray', 'devices/xray', 'Raio-X', 'saude', [
    'raio-x', 'raio x', 'raios x', 'radiografia', 'radiolog', 'tomografia', 'panoramica', 'exames de imagem',
    'exame de imagem', 'diagnostico por imagem', 'rx',
  ]),
  hi('mouth', 'body/mouth', 'Boca', 'saude', [
    'boca', 'labio', 'labial', 'preenchimento labial', 'gengiva', 'gengival', 'periodont', 'halitose', 'atm',
  ]),
  ph('tooth', 'Dente', 'saude', [
    'dente', 'dental', 'dentaria', 'dentario', 'dentista', 'odontolog', 'odonto', 'sorriso', 'clareamento dental',
    'protese', 'lentes de contato dental', 'lente de contato dental', 'faceta', 'restauracao dental',
    'restauracoes', 'obturac', 'tratamento de canal', 'endodont', 'canal radicular', 'carie', 'siso',
    'extracao', 'bruxismo', 'dentadura',
  ]),
  // ---------------------------------------------------------------- saúde: especialidades
  hi('pediatrics', 'specialties/pediatrics', 'Pediatria', 'saude', [
    'pediatr', 'odontopediatr', 'infantil', 'atendimento infantil', 'crianca', 'infancia', 'bebe', 'recem-nascido',
    'neonatal', 'kids',
  ]),
  hi('physical-therapy', 'specialties/physical_therapy', 'Fisioterapia', 'saude', [
    'fisioterap', 'fisio', 'reabilita', 'pos-operatorio', 'pos operatorio', 'terapia manual', 'osteopatia',
    'coluna', 'rpg', 'postura', 'postural', 'quiropraxia', 'escoliose', 'lombar', 'cervical', 'hernia de disco',
    'dor nas costas', 'costas',
    'pilates', 'yoga', 'ioga', 'alongamento', 'mobilidade', 'flexibilidade',
  ]),
  hi('joints', 'body/joints', 'Articulações', 'saude', [
    'articula', 'joelho', 'ombro', 'ortoped', 'traumatolog', 'artrose', 'tendinite', 'lesoes esportivas',
    'lesao', 'lesoes',
  ]),
  hi('sterilization', 'devices/surgical_sterilization', 'Esterilização', 'saude', [
    'esteriliza', 'biosseguranca', 'autoclave', 'instrumental', 'cirurgia', 'cirurgico', 'cirurgica',
  ]),
  hi('nutrition', 'nutrition/nutrition', 'Nutrição', 'saude', [
    'nutri', 'alimentacao', 'alimentar', 'reeducacao alimentar', 'dieta', 'emagrec',
  ]),
  ph('sparkle', 'Brilho', 'saude', [
    'harmonizacao', 'estetica', 'estetico', 'clareamento', 'rejuvenesc', 'limpeza de pele', 'limpeza facial',
    'facial', 'pele', 'skincare', 'peeling', 'beleza', 'brilho', 'manchas', 'acne', 'dermatolog', 'micropigmenta',
    'design de sobrancelha', 'sobrancelha',
  ]),
  ph('syringe', 'Seringa', 'saude', [
    'vacina', 'injec', 'injetav', 'toxina botulinica', 'botox', 'preenchimento', 'bioestimulador', 'soroterapia',
    'aplicacao de', 'enzimas',
  ]),
  ph('pill', 'Comprimido', 'saude', [
    'medicamento', 'medicacao', 'remedio', 'farmacia', 'farmaceutic', 'prescricao', 'suplement', 'vitamina',
    'manipulac',
  ]),
  ph('brain', 'Cérebro', 'saude', [
    'neurolog', 'cerebro', 'neuropsicolog', 'psiquiatr', 'memoria', 'cognitiv', 'tdah', 'autismo', 'tea',
    'neurodesenvolvimento', 'aprendizagem',
    'psicolog', 'psicoterap', 'terapia', 'terapia de casal', 'terapia familiar', 'saude mental', 'ansiedade',
    'depressao', 'emocional', 'emocoes', 'autoestima', 'psicologia infantil',
  ]),
  ph('eye', 'Olho', 'saude', [
    'oftalmo', 'olho', 'saude da visao', 'exame de vista', 'oculos', 'lentes de contato', 'catarata', 'glaucoma',
    'transparencia',
  ]),
  ph('barbell', 'Haltere', 'saude', [
    'academia', 'musculacao', 'treino', 'exercicio', 'condicionamento', 'fortalecimento', 'personal trainer',
    'atividade fisica', 'esporte', 'esportiv',
  ]),
  ph('flower-lotus', 'Flor de lótus', 'saude', [
    'bem-estar', 'bem estar', 'relaxa', 'massag', 'massoterap', 'spa', 'meditac', 'mindfulness', 'acupuntura',
    'aromaterapia', 'drenagem linfatica', 'integrativa', 'holistic', 'reiki',
  ]),
  ph('heartbeat', 'Batimentos', 'saude', [
    'cardiolog', 'cardiac', 'coracao', 'eletrocardiograma', 'ecg', 'holter', 'pressao arterial', 'hipertensao',
  ]),
  ph('first-aid-kit', 'Primeiros socorros', 'saude', [
    'urgencia', 'emergencia', 'pronto atendimento', 'pronto-socorro', 'pronto socorro', 'primeiros socorros',
    'atendimento de emergencia', 'atendimento de urgencia', 'socorro',
  ]),
  ph('hand-heart', 'Mão com coração', 'saude', [
    'acolhimento', 'acolhedor', 'humaniza', 'atendimento humanizado', 'atendimento acolhedor', 'cuidado', 'empatia',
    'carinho', 'responsabilidade social', 'social', 'voluntari',
  ]),
  ph('stethoscope', 'Estetoscópio', 'saude', [
    'consulta', 'clinica geral', 'clinico geral', 'clinica medica', 'medico', 'medica', 'medicina', 'check-up',
    'checkup', 'exame clinico', 'exame', 'ambulatori', 'geriatr', 'endocrino', 'ginecolog', 'obstetr',
    'saude da mulher', 'pre-natal', 'gestante', 'gravidez', 'telemedicina',
  ]),
  ph('heart', 'Coração', 'saude', [
    'saude', 'qualidade de vida', 'dedicacao', 'amor', 'paixao',
  ]),

  // ---------------------------------------------------------------- empresas e serviços técnicos
  ph('solar-panel', 'Painel solar', 'empresas', [
    'energia solar', 'solar', 'fotovolt', 'placa solar', 'placas solares', 'painel solar', 'paineis solares',
    'usina solar', 'geracao distribuida', 'geracao de energia',
  ]),
  ph('lightning', 'Raio', 'empresas', [
    'projetos eletricos', 'projeto eletrico', 'eletric', 'engenharia eletrica', 'energia', 'subestac',
    'cabine primaria', 'media tensao', 'baixa tensao', 'alta tensao', 'tensao', 'spda', 'para-raios', 'pararraios',
    'eficiencia energetica', 'economia de energia', 'gerador', 'quadro de distribuicao', 'quadros de distribuicao',
    'nr-10', 'nr10', 'instalacao eletrica', 'instalacoes eletricas', 'tomada', 'recarga', 'carregador', 'wallbox',
    'veiculo eletrico', 'carro eletrico',
    'iluminac', 'luminotecnic', 'led',
  ]),
  ph('snowflake', 'Floco de neve', 'empresas', [
    'climatiza', 'ar-condicionado', 'ar condicionado', 'refrigera', 'camara fria', 'hvac', 'pmoc',
  ]),
  ph('fire-extinguisher', 'Extintor', 'empresas', [
    'incendio', 'prevencao de incendio', 'combate a incendio', 'extintor', 'hidrante', 'ppci', 'avcb', 'clcb',
    'bombeiro', 'brigada',
  ]),
  ph('security-camera', 'Câmera de segurança', 'empresas', [
    'cftv', 'camera', 'monitoramento', 'seguranca eletronica', 'controle de acesso', 'alarme', 'sistema de alarme',
    'sistemas de alarme', 'portaria', 'cerca eletrica', 'vigilancia',
  ]),
  ph('hard-hat', 'Capacete', 'empresas', [
    'seguranca do trabalho', 'saude ocupacional', 'engenharia de seguranca', 'acidente de trabalho', 'epi', 'epis',
    'normas regulamentadoras', 'nr-35', 'nr-12', 'obra', 'construc', 'construtora', 'construcao civil', 'engenharia civil',
    'canteiro', 'edificac', 'empreiteira', 'terraplanagem',
  ]),
  ph('hammer', 'Martelo de obra', 'empresas', [
    'reforma', 'marcenaria', 'carpintaria', 'alvenaria', 'acabamento', 'pequenos reparos', 'pintura', 'drywall',
    'gesso', 'montagem',
  ]),
  ph('wrench', 'Chave inglesa', 'empresas', [
    'manutenc', 'conserto', 'reparo', 'assistencia tecnica', 'mecanic', 'oficina', 'termografia',
    'ferramenta', 'servicos gerais', 'facilities', 'multisservicos', 'manutencao predial', 'marido de aluguel',
    'faz-tudo',
  ]),
  ph('drop', 'Gota', 'empresas', [
    'hidraul', 'encanamento', 'agua', 'vazamento', 'esgoto', 'saneamento', 'irrigac', 'tubulac', 'reuso',
    'drenagem', 'hidratac', 'impermeabiliza',
  ]),
  ph('ruler', 'Régua', 'empresas', [
    'projeto', 'projetista', 'arquitet', 'estrutura', 'calculo estrutural', 'desenho tecnico', 'topograf',
    'medicao', 'medicoes', 'as built', 'as-built', 'dimensionamento', 'compatibiliza', 'planta baixa', 'interiores',
  ]),
  ph('factory', 'Fábrica', 'empresas', [
    'industria', 'fabrica', 'producao', 'linha de producao', 'manufatura', 'usinagem', 'metalurg', 'caldeiraria',
    'processos industriais',
  ]),
  ph('truck', 'Caminhão', 'empresas', [
    'transport', 'logistica', 'frete', 'entrega', 'mudanca', 'frota', 'distribuic', 'remocao',
  ]),
  ph('package', 'Pacote', 'empresas', [
    'embalag', 'estoque', 'armazenag', 'armazem', 'galpao', 'galpoes', 'deposito', 'centro de distribuicao',
    'produto', 'insumo', 'suprimento', 'fornecimento', 'pecas', 'materiais', 'kit',
  ]),
  ph('broom', 'Vassoura', 'empresas', [
    'limpeza', 'conservac', 'zeladoria', 'higieniza', 'faxina', 'pos-obra', 'limpeza pos-obra',
  ]),
  ph('leaf', 'Folha', 'empresas', [
    'ambiental', 'ambientais', 'meio ambiente', 'sustentab', 'sustentavel', 'ecologic', 'jardin', 'paisagis', 'poda', 'organico', 'licenciamento ambiental',
    'recicla', 'residuo', 'coleta seletiva', 'descarte', 'pgrs', 'logistica reversa', 'economia circular',
  ]),
  ph('headset', 'Fone de atendimento', 'empresas', [
    'suporte', 'help desk', 'helpdesk', 'central de atendimento', 'sac', 'call center', 'callcenter', 'televendas',
    'atendimento ao cliente', 'atendimento telefonico', 'service desk',
  ]),
  ph('desktop', 'Computador', 'empresas', [
    'tecnologia', 'ti', 'informatica', 'sistema', 'software', 'computador', 'digital', 'site', 'website', 'nuvem',
    'cloud', 'backup', 'erp', 'banco de dados', 'automac', 'automatiza', 'robotica', 'inteligencia artificial', 'ia',
    'chatbot',
    'wifi', 'wi-fi', 'internet', 'rede', 'conectividade', 'cabeamento', 'fibra optica',
  ]),
  ph('megaphone', 'Megafone', 'empresas', [
    'marketing', 'publicidade', 'divulga', 'anuncio', 'redes sociais', 'propaganda', 'campanha', 'trafego pago',
    'comunicacao visual', 'branding', 'midia', 'assessoria de imprensa', 'identidade visual',
  ]),
  ph('storefront', 'Loja', 'empresas', [
    'loja', 'varejo', 'comercio', 'franquia', 'ponto de venda', 'restaurante', 'lanchonete', 'food service',
  ]),
  ph('shopping-cart', 'Carrinho', 'empresas', [
    'consumidor', 'compra', 'e-commerce', 'ecommerce', 'loja virtual', 'supermercado', 'mercado', 'venda',
  ]),
  ph('file-magnifying-glass', 'Documento com lupa', 'empresas', [
    'laudo', 'laudos', 'auditoria', 'due diligence', 'analise documental', 'revisao de contrato', 'pericia',
  ]),
  ph('clipboard-text', 'Prancheta', 'empresas', [
    'consultoria tecnica', 'vistoria', 'inspec', 'checklist', 'avaliacao tecnica', 'avaliac', 'diagnostico',
    'relatorio tecnico', 'plano de acao', 'plano de tratamento', 'anamnese', 'triagem', 'visita tecnica',
  ]),
  ph('magnifying-glass', 'Lupa', 'geral', [
    'analise', 'pesquisa', 'investiga', 'busca', 'seo', 'levantamento', 'procura',
  ]),
  ph('chalkboard-teacher', 'Treinamento', 'geral', [
    'treinamento', 'capacita', 'curso', 'workshop', 'palestra', 'mentoria', 'aula', 'ensino', 'educacao corporativa',
  ]),
  ph('graduation-cap', 'Formatura', 'geral', [
    'formacao', 'graduacao', 'especializacao', 'mestrado', 'doutorado', 'pos-graduacao', 'educacao', 'escola',
    'faculdade', 'universidad', 'titulac', 'academic',
  ]),

  // ---------------------------------------------------------------- jurídico
  ph('scroll', 'Pergaminho', 'juridico', [
    'inventario', 'heranca', 'testamento', 'partilha', 'espolio', 'doacao', 'planejamento sucessorio',
  ]),
  ph('users-three', 'Família', 'juridico', [
    'familia', 'divorcio', 'guarda', 'pensao alimenticia', 'uniao estavel', 'casamento', 'casal', 'sucessoes',
    'sucessao', 'adocao', 'paternidade',
  ]),
  ph('identification-badge', 'Crachá', 'juridico', [
    'trabalhist', 'trabalho', 'emprego', 'clt', 'rescisao', 'demissao', 'carteira de trabalho',
    'departamento pessoal', 'recursos humanos', 'rh', 'admissao', 'esocial', 'assedio', 'horas extras',
  ]),
  ph('identification-card', 'Documento pessoal', 'juridico', [
    'previdenc', 'planejamento previdenciario', 'aposentad', 'inss', 'beneficio', 'auxilio', 'bpc', 'loas',
    'pensao por morte', 'documento pessoal', 'documentos pessoais', 'identidade', 'cpf', 'rg', 'convenio',
    'plano de saude', 'planos de saude', 'carteirinha', 'cadastr', 'pessoa fisica',
  ]),
  ph('house-line', 'Casa', 'juridico', [
    'imobiliari', 'imove', 'aluguel', 'alugueis', 'locacao', 'locacoes', 'despejo', 'usucapiao', 'casa', 'residenc',
    'domicil', 'moradia', 'lar', 'home care', 'regularizacao de imove', 'atendimento domiciliar',
    'atendimento a domicilio',
  ]),
  ph('car', 'Carro', 'juridico', [
    'transito', 'multa', 'acidente', 'veiculo', 'automov', 'automotiv', 'cnh', 'carro', 'detran', 'seguro auto',
    'habilitacao',
  ]),
  ph('gavel', 'Martelo', 'juridico', [
    'judicia', 'processo judicial', 'processos judiciais', 'processual', 'audiencia', 'tribunal', 'contencioso',
    'litigio', 'recurso', 'criminal', 'direito penal', 'penal', 'juri', 'leilao', 'leiloes', 'defesa',
    'execucao fiscal', 'cobranca judicial',
  ]),
  ph('signature', 'Assinatura', 'juridico', [
    'assinatura', 'assinar', 'procurac', 'cartorio', 'escritura', 'reconhecimento de firma', 'notarial', 'autentica',
  ]),
  ph('certificate', 'Certificado', 'juridico', [
    'certificad', 'certificac', 'alvara', 'licenca', 'licenciamento', 'homologa', 'registro de marca',
    'marcas e patentes', 'patente', 'diploma', 'propriedade intelectual',
  ]),
  ph('file-text', 'Documento', 'juridico', [
    'contrato', 'documento', 'papelada', 'certidao', 'burocracia', 'art', 'minuta', 'notificac', 'registro',
    'termos de uso', 'proposta',
  ]),
  ph('lock', 'Cadeado', 'juridico', [
    'sigilo', 'confidencial', 'discricao', 'privacidade', 'lgpd', 'protecao de dados', 'senha', 'criptografia',
    'cofre',
  ]),

  // ---------------------------------------------------------------- finanças
  // "Pessoa" precisa vir antes da calculadora: em "Contador dedicado", "dedicado" e
  // "contador" têm 8 letras e o empate vai para a ordem do arquivo (PDF §8.2).
  ph('user-circle', 'Pessoa', 'geral', [
    'dedicado', 'dedicada', 'exclusiv', 'individual', 'perfil', 'pessoal',
  ]),
  ph('calculator', 'Calculadora', 'financas', [
    'contabil', 'contador', 'contadora', 'assessoria contabil', 'consultoria contabil', 'calculo', 'orcamento', 'custo', 'precifica', 'escrituracao', 'balanc',
    'demonstrativ',
  ]),
  ph('receipt', 'Recibo', 'financas', [
    'fiscal', 'nota fiscal', 'notas fiscais', 'emissao de notas', 'imposto', 'tribut', 'direito tributario', 'guia',
    'simples nacional', 'imposto de renda', 'declarac', 'irpf', 'irpj', 'honorario', 'boleto', 'nfe', 'nfs-e',
    'recibo', 'recuperacao tributaria', 'reforma tributaria', 'restituic', 'compensac',
  ]),
  ph('chart-line-up', 'Gráfico em alta', 'financas', [
    'planejamento', 'investiment', 'crescimento', 'rentabil', 'lucro', 'desempenho', 'performance', 'resultado',
    'valoriza', 'previsao', 'projecao', 'consultoria financeira', 'mercado financeiro', 'bolsa de valores',
    'renda fixa', 'renda variavel', 'faturamento', 'metas', 'produtividade', 'kpi', 'vendas',
  ]),
  ph('bank', 'Banco', 'financas', [
    'banco', 'bancari', 'credito', 'emprestimo', 'financiament', 'consignado', 'consorcio', 'refinancia',
    'portabilidade', 'home equity', 'correspondente bancario', 'cambio',
  ]),
  ph('piggy-bank', 'Cofrinho', 'financas', [
    'economia', 'economiz', 'poupanca', 'reserva', 'previdencia privada', 'educacao financeira',
    'financas pessoais', 'orcamento familiar', 'controle financeiro', 'organizacao financeira', 'gastos', 'despesa',
  ]),
  ph('credit-card', 'Cartão', 'financas', [
    'cartao', 'maquininha', 'pagamento por cartao', 'credito e debito', 'debito', 'pix',
  ]),
  ph('coins', 'Moedas', 'financas', [
    'financeir', 'financas', 'fluxo de caixa', 'dinheiro', 'pagamento', 'cobranca', 'receb', 'capital de giro',
    'tesouraria', 'contas a pagar', 'contas a receber', 'juros', 'taxa',
    'folha de pagamento', 'folha', 'salario', 'pro-labore', 'prolabore', 'remunerac', 'antecipac', 'comiss',
  ]),
  ph('chart-pie', 'Gráfico de pizza', 'financas', [
    'relatorio', 'indicador', 'dashboard', 'gestao', 'bpo', 'bpo financeiro', 'controladoria', 'analise financeira',
    'diagnostico financeiro',
  ]),

  // ---------------------------------------------------------------- gerais (do mais específico ao mais genérico)
  ph('medal', 'Medalha', 'geral', [
    'experiencia', 'anos de experiencia', 'anos de mercado', 'qualificac', 'qualificad', 'premio', 'premia',
    'reconhecimento', 'excelencia', 'especialista', 'especializad', 'tradicao', 'referencia', 'destaque', 'premium',
    'vip', 'diferencia',
  ]),
  ph('thumbs-up', 'Joinha', 'geral', [
    'satisfac', 'satisfeito', 'recomend', 'indicac', 'facil', 'simples', 'descomplic', 'sem complicacao',
    'sem burocracia', 'pratic',
  ]),
  ph('rocket', 'Foguete', 'geral', [
    'abertura de empresa', 'abertura', 'startup', 'lancamento', 'comece', 'crescer', 'impulsion', 'aceler',
    'expansao', 'novo negocio', 'empreend', 'mei', 'rapid', 'agil', 'express', 'atendimento rapido',
  ]),
  ph('clock', 'Relógio', 'geral', [
    '24 horas', '24h', 'plantao', 'horario', 'pontual', 'noturno', 'turno', 'atendimento 24 horas',
    'atendimento 24h',
  ]),
  ph('calendar-check', 'Agenda', 'geral', [
    'agenda', 'prazo', 'data marcada', 'hora marcada', 'vencimento', 'calendario', 'sabado', 'domingo',
    'fim de semana', 'fins de semana', 'obrigacoes', 'rotina', 'mensal', 'semanal', 'periodic', 'retorno',
    'cronograma',
  ]),
  ph('map-pin', 'Local', 'geral', [
    'localizac', 'endereco', 'local', 'presencial', 'unidade', 'regiao', 'perto de voce', 'proximo', 'visita',
  ]),
  ph('globe', 'Globo', 'geral', [
    'online', 'remot', 'a distancia', 'videochamada', 'video chamada', 'videoconferencia', 'nacional',
    'todo o brasil', 'exportacao', 'importacao', 'comercio exterior', 'online e presencial', 'presencial e online',
    'atendimento online', 'atendimento virtual', 'idioma', 'ingles',
  ]),
  ph('device-mobile', 'Celular', 'geral', [
    'celular', 'pelo celular', 'aplicativo', 'app', 'smartphone', 'whatsapp', 'mobile', 'mensagem',
  ]),
  ph('phone', 'Telefone', 'geral', [
    'telefon', 'ligacao', 'ligue', '0800',
  ]),
  ph('envelope', 'E-mail', 'geral', [
    'e-mail', 'email', 'newsletter', 'correspondencia', 'correio', 'carta',
  ]),
  ph('users', 'Pessoas', 'geral', [
    'equipe', 'time', 'colaborador', 'funcionario', 'recrutamento', 'selecao', 'gestao de pessoas', 'mao de obra',
    'pessoas', 'clientes', 'processo seletivo',
  ]),
  ph('handshake', 'Aperto de mãos', 'geral', [
    'acordo', 'mediac', 'concilia', 'negocia', 'renegocia', 'divida', 'parceri', 'parceiro',
    'atendimento personalizado', 'personaliza', 'relacionamento', 'confianca', 'compra e venda',
  ]),
  ph('seal-check', 'Selo de qualidade', 'geral', [
    'regulariz', 'conformidade', 'certidao negativa', 'certidoes negativas', 'qualidade', 'garantia', 'aprovac',
    'aprovad', 'legaliza', 'adequac', 'iso', 'normas tecnicas', 'norma', 'padrao', 'padroes', 'padroniza',
    'revisao', 'conferencia', 'verificac', 'em dia', 'tudo certo', 'conclu', 'pronto', 'checagem',
  ]),
  ph('shield-check', 'Escudo', 'geral', [
    'seguranca', 'protecao', 'seguro', 'compliance', 'prevencao', 'risco', 'integridade',
  ]),
  ph('chat-circle-dots', 'Conversa', 'geral', [
    'atendimento', 'conversa', 'escuta', 'orientac', 'duvida', 'linguagem', 'comunicacao clara', 'explica',
    'contato',
  ]),
  ph('gear', 'Engrenagem', 'empresas', [
    'engenharia', 'processo', 'operac', 'maquina', 'equipamento', 'mecaniza', 'configurac', 'otimiza',
    'melhoria continua', 'lean', 'gestao de processos', 'automacao industrial', 'clp', 'plc',
  ]),
  ph('briefcase', 'Maleta', 'geral', [
    'consultoria', 'assessoria', 'negocio', 'gestao empresarial', 'carreira', 'executiv', 'terceiriza',
    'outsourcing', 'administrac', 'servicos para empresas',
  ]),
  ph('buildings', 'Prédios', 'geral', [
    'empresarial', 'empresa', 'societari', 'corporativ', 'condominio', 'predial', 'predio', 'edificio', 'comercial',
    'fachada', 'holding',
  ]),
  ph('scales', 'Balança', 'juridico', [
    'direito', 'juridic', 'advocacia', 'advogad', 'justica', 'lei', 'leis', 'legal', 'civil', 'oab',
    'orientacao juridica', 'consultoria juridica', 'assessoria juridica', 'consulta juridica', 'suporte juridico',
    'constitucional',
  ]),
];

/**
 * Ícones derivados (Healthicons 2.0 não tem aparelho ortodôntico): o dente do Healthicons
 * com fio e bráquete desenhados por cima. Coordenadas no viewBox 0 0 48 48.
 *  - traço (fino/duotone): contorno do dente + fio e bráquete preenchidos;
 *  - preenchido: dente sólido com fio e bráquete vazados (fill-rule evenodd num só path).
 */
export const DERIVADOS = {
  braces: {
    base: 'body/tooth',
    // Fio de 1,5 de espessura atravessando o dente (passa um pouco das bordas: "liga" os dentes).
    fio: 'M4.5 21.25H43.5V22.75H4.5Z',
    // Bráquete: quadrado com cantos arredondados no centro do fio.
    braquete: 'M21 17.5H27Q28.5 17.5 28.5 19V25Q28.5 26.5 27 26.5H21Q19.5 26.5 19.5 25V19Q19.5 17.5 21 17.5Z',
    // Versão vazada (preenchido): fio em dois trechos que não encostam no bráquete e um anel em volta dele.
    vazado: 'M4.5 21.25H18V22.75H4.5ZM30 21.25H43.5V22.75H30Z'
      + 'M21 16H27Q30 16 30 19V25Q30 28 27 28H21Q18 28 18 25V19Q18 16 21 16Z'
      + 'M21.5 18.5H26.5Q27.5 18.5 27.5 19.5V24.5Q27.5 25.5 26.5 25.5H21.5Q20.5 25.5 20.5 24.5V19.5Q20.5 18.5 21.5 18.5Z',
  },
};

// Glifos só de traço (check, +, −, ×, menu): o "fill" do Phosphor vira um quadrado cheio com o
// glifo vazado e o duotone ganha um quadrado de fundo — ruins em botões. Para eles o duotone usa
// o peso regular e o preenchido usa o negrito (bold).
const SO_TRACO = { duotone: 'regular', preenchido: 'bold' };

/** Ícones utilitários (contrato §3.7): chave → ícone Phosphor (+ pesos trocados, se houver). */
export const UTILITARIOS = {
  seta: { arquivo: 'arrow-right', nome: 'Seta' },
  check: { arquivo: 'check', nome: 'Check', pesos: SO_TRACO },
  estrela: { arquivo: 'star', nome: 'Estrela' },
  pin: { arquivo: 'map-pin', nome: 'Alfinete de mapa' },
  relogio: { arquivo: 'clock', nome: 'Relógio' },
  telefone: { arquivo: 'phone', nome: 'Telefone' },
  email: { arquivo: 'envelope-simple', nome: 'E-mail' },
  whatsapp: { arquivo: 'whatsapp-logo', nome: 'WhatsApp' },
  instagram: { arquivo: 'instagram-logo', nome: 'Instagram' },
  facebook: { arquivo: 'facebook-logo', nome: 'Facebook' },
  linkedin: { arquivo: 'linkedin-logo', nome: 'LinkedIn' },
  youtube: { arquivo: 'youtube-logo', nome: 'YouTube' },
  google: { arquivo: 'google-logo', nome: 'Google' },
  mais: { arquivo: 'plus', nome: 'Mais', pesos: SO_TRACO },
  menos: { arquivo: 'minus', nome: 'Menos', pesos: SO_TRACO },
  aspas: { arquivo: 'quotes', nome: 'Aspas' },
  mapa: { arquivo: 'map-trifold', nome: 'Mapa' },
  menu: { arquivo: 'list', nome: 'Menu', pesos: SO_TRACO },
  fechar: { arquivo: 'x', nome: 'Fechar', pesos: SO_TRACO },
  calendario: { arquivo: 'calendar-blank', nome: 'Calendário' },
  circulo: { arquivo: 'circle', nome: 'Círculo' },
};
