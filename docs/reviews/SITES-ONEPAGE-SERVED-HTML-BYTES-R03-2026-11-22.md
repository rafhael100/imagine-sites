# OnePage R03 — bytes observados pelo navegador (somente candidato offline, NO-GO)

Data: 2026-11-22. Filha independente da Sites #606 na HEAD fixa `af6e9e781808cb57409953524e2840622de6751b`. A OnePage #605 é outra trilha de SQL e permanece intacta. Sites #541/#542 e Portal #976/#1003 são referências, não autoridade operacional.

## Lacuna que a #606 deixou aberta

A testemunha independente de apresentação exigia `observed: true` e conferia o `htmlSha256` declarado. Se uma origem enviasse o digest correto junto com **bytes diferentes dos vistos**, o aceite passaria sem revalidar os bytes da própria apresentação. Um SHA copiado de metadados não prova conteúdo efetivamente apresentado.

## Modificação mínima e ensaio

A porta de leitura `currentPresentation` do candidato `OnepageExactFinalAcceptanceReadCandidate` agora exige `servedHtmlBytes` (string não vazia, limite de 262.144 bytes), calcula SHA-256 desses próprios bytes e compara o conteúdo byte a byte com a saída do renderizador previamente revisada. Na segunda leitura, qualquer alteração dos bytes ou demais campos mantém a negativa. A estrutura do contrato de copy `sites.ai-copy.v1` e o renderizador não mudaram.

O teste fictício existente conserva empresa/pedido/comprador/site/revisão e foi estendido com negativas para: digest declarado correto com conteúdo trocado, bytes vazios, tipo inválido, campo ausente, excesso de tamanho, mudança dos bytes entre duas leituras e reedição v2 com SHA e apresentação novos. O HTML original e o v2 vêm do renderizador local em memória; o teste não fornece sessão nativa, transporte HTTP autenticado nem um navegador real. Não equivale à evidência operacional de entrega.

## Regras de integração futura

A testemunha não pode ser fabricada a partir do recibo de clique, parâmetro do cliente ou do próprio renderizador em produção: **somente** uma observação independente, persistida por rota privada autenticada e vinculada ao comprador/pedido/release/época, poderá satisfazer esse requisito. O candidato ainda é read-only e não cria recibo nem decide publicar; nenhuma fonte oficial foi atribuída. Preserva `clientFinalAccepted` separado de `publicationAuthorized: false`, nunca transfere autoridade da Landing, nem desbloqueia Sites #38.

CI: workflow restrito a esta PR draft, SHA fixa da base, PHP 8.3/8.4, somente fixtures sintéticas; `contents: read`, sem checkout com credencial persistida, API, VPS, PSP, DNS ou deploy. Registrar o resultado real após a execução; não deduzir sucesso apenas do código.

Próxima dependência: constatar no Portal a origem operacional da revisão imutável, aceite do comprador e autenticação da observação HTTP na mesma versão. Worker OpenAI real continua exigindo identidade/egress/escopo próprios atestados.