# F1-169 — "Como funciona" em Onepage e Landing Page

Entrega funcional: o renderer gera uma seção **Como funciona** opcional com lista numerada nativa, acessível e responsiva, para explicar etapas de contratação ou prestação de um serviço. Cada item contém título e descrição em texto simples, sem botões, links externos, formulário ou script.

O SiteBrief aceita até cinco etapas, em ordem definida pela equipe Imagine, com título limitado a 100 bytes e descrição a 350 bytes. Rejeita estrutura extra, duplicatas de título, dados malformados e URLs. O HTML escapa o conteúdo e só incorpora CSS adicional quando existem etapas, preservando **byte a byte** as prévias anteriores sem o bloco. O InertPreviewAudit reconhece apenas a nova tag estática ol.

O contrato de conteúdo sintético ganha a versão opt-in sites.ai-copy.v4, com processSteps obrigatório (1–5) e campos v3 anteriores preservados. v1, v2 e v3 não aceitam esse campo. Produto, marca, tema e empresa permanecem controlados pelo motor, não pelo conteúdo recebido. Montagem de rascunho, alteração de estilo e revisão de conteúdo preservam as etapas; uma mudança em qualquer item gera digest novo, invalida a revisão anterior e requer nova aprovação humana.

Testes PHP8.3/8.4 abrangem 2 produtos × 3 temas, ordem, semântica, integridade, entrada adversarial, contratos v1–v4 e regressões de FAQ e serviços.

É apenas artefato privado de laboratório: OP01/OP02/OP04 e os dez gates de produção continuam bloqueados; sem coleta, autenticação, publicação, envio CRM, VPS, custos nem merge na main.
