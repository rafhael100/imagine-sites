# F1-246 — paridade de hashes entre PHP 8.3 e PHP 8.4

## Risco corrigido

As prévias do Imagine Sites são vinculadas ao conteúdo exato por SHA-256. Uma revisão/aceite de um digest não pode ser reaproveitada em outro release. Até F1-245, o CI rodava os mesmos geradores em **PHP 8.3 e 8.4**, mas verificava as duas execuções **isoladamente**; não havia prova de que ambas produziam **exatamente os mesmos bytes** de HTML e manifesto.

## Teste de compatibilidade real

O workflow existente **Sites F1-238 six inert demonstration pages...** continua usando sua matriz PHP 8.3/8.4, sem novas rodadas de build. Cada job gera seis arquivos reais pelo pipeline `StructuredCopyCandidate` → `SyntheticStructuredDraftAssembly` e salva o `manifest.json` com os SHA-256 e tamanhos de cada HTML.

Agora os dois jobs publicam *apenas* seus manifestos fictícios como artefatos efêmeros separados (`sites-f246-manifest-php-8.3` e `sites-f246-manifest-php-8.4`, retenção de 1 dia). Após ambos passarem, um terceiro job lê os dois arquivos e executa:

- `ci/f246_php_manifest_parity.py`, que rejeita JSON com chaves repetidas, dados malformados ou campos e tipos incoerentes;
- valida presença dos seis HTMLs (Onepage/Landing × Classic/Modern/Warm), seus SHA-256 e tamanho, sem URLs, segredos ou nomes de clientes;
- exige `publishable=false`, `approvedForClient=false`, `internalReviewRequired=true`, `portalAuthorityRequired=true`, `draftState=internal_review`, `hasContactCapture=false` em ambas as execuções;
- compara **todos os bytes** dos manifestos, não só os rótulos de sucesso, e falha com qualquer diferença de digest, tamanho, schema, flags ou organização de arquivos;
- cobre casos negativos em `--self-test`: alteração de digest, aprovação indevida, remoção da revisão obrigatória e JSON ambíguo;
- executa `cmp` adicional dos dois arquivos e apaga os manifestos do runner.

Se PHP 8.3 e PHP 8.4 passarem individualmente mas divergirem em bytes de página ou manifesto, a nova etapa **falha** e exige correção. Isso é necessário para preservar revisões vinculadas a artefatos imutáveis ao longo de upgrades da versão de PHP.

**Limites:** a prova é de **HTML sintético privado** com dados fictícios, não assinatura criptográfica de produção, aprovação de cliente, compatibilidade de banco real, integração de IA/CRM ou autorização de publicação. Não altera `dev`, `main`, VPS, Cloudflare, DNS ou os dez gates bloqueados.
