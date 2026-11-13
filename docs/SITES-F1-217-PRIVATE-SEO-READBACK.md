# F1-217 — Conferência SEO dos quatro arquivos de revisão já exportados

Depois de a F1-215 inspecionar o HTML em memória e a F1-216 apresentar o resultado ao revisor Laravel, a F1-217 verifica que o **mesmo relatório pode ser reconstruído dos bytes privados exportados**, sem aceitar dados ou permissões externos.

O serviço `SyntheticPrivateSeoSnippetReadbackV1::inspect` recebe exatamente os quatro arquivos canônicos da página privada, além do `SiteBrief` de referência. Ele chama `SyntheticPrivateDraftVerifier::verifyBytes`, exige `reviewerDecisionStatus=pending`, `publishable=false`, `officialClientAcceptance=false`, recompõe o HTML pela renderização canônica e executa a inspeção SEO F1-215 sobre o mesmo `index.html`. A identidade SHA-256, o título, H1 e descrição não podem divergir. **Um readback não constitui publicação, aprovação nem permissão de indexação.**

O teste PHP8.3/8.4 usa 24 combinações de Onepage/Landing, temas, paletas e metadados sociais ligados/desligados. Injeta corrupção de HTML, mudança de robots para index, resumo publishable=true, decisão do revisor falsificada e briefing de outra versão, exigindo rejeição. O teste faz parte do gate permanente Sites Engine em PR e main, sem serviços de terceiros.

Esta entrega continua exclusivamente em laboratório com dados sintéticos, noindex e arquivo local privado. Não faz pesquisa pública, não se conecta ao Google nem ao Imagine SEO Engine, não transmite lead ao CRM e não implementa aceite. Dez gates operacionais, OP01/02/04, credenciais reais e homologação de produção continuam bloqueados, sem merge ou deploy.
