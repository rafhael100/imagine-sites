# F1-202 — Ajustes visuais numa única nova revisão

Até agora o ecossistema Imagine Sites possuía operações isoladas para trocar tema, cores de marca, ordem das seções e menu interno. Esta entrega permite preparar **as quatro mudanças em um único plano imutável**, evitando quatro processos de revisão separados. É um plano sintético em memória, não uma transação, editor de cliente ou autorização operacional.

SyntheticCompositeVisualRevisionPlan::begin exige um workflow fictício previamente revisado, revisão esperada, role de laboratório imagine_reviewer, identidade do HTML anterior e releaseRef novo. Regera os bytes da página anterior antes de aceitar qualquer mudança. O novo SiteBrief conserva produto, marca, títulos, benefícios, descrição, CTA, serviços, etapas e FAQ; somente as quatro opções visuais tipadas podem mudar.

A validação de contraste da paleta continua a cargo de SyntheticBrandPaletteV1, que só aceita quatro cores RGB #rrggbb com contraste mínimo 4,5:1 nos pares definidos. SiteBrief continua restringindo temas Classic/Modern/Warm, as seis permutações de três seções e menu extra apenas para Onepage. Se não houver diferença de HTML ou ocorrer erro na auditoria inerte, QA, manifesto, revisão ou ator, o plano falha sem efeitos.

O resultado volta o workflow a DRAFTING sem digest aprovado e não autoriza publicação, aceite do cliente, entrega de lead ao CRM ou reutilização do aceite antigo. Testes PHP 8.3/8.4 cobrem **54 mudanças válidas**, incluindo retirar uma paleta opcional, mudanças independentes e combinadas, além de entradas inválidas, CSS injetado, cor sem contraste, HTML original adulterado, revisões obsoletas, role indevido, destino Landing com menu e tentativa de reutilizar o release.

O teste é integrado ao Sites Engine e mantém a cadeia PR draft sem deploy, merge em main, fornecedores pagos, dados pessoais ou ações na infraestrutura. A autorização real do Portal e os gates OP01/02/04 continuam pendentes.
