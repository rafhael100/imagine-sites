# Imagine Sites F1-293 — verificação HMAC v3 de JSON bruto sem ambiguidades

**12/11/2026 | Etapa técnica de segurança | Somente laboratório offline | OP01 continua bloqueado**

## Risco comprovado no contrato

- `SyntheticCommercialEpochSignedCommand::verifySignature(array...)` verifica um array de campos ordenados, mas uma API HTTP futura poderia obter esse array via `json_decode($raw,true)`. Esse parse descarta informação do wire: chaves duplicadas, formas escapadas, espaços, ordenação alternativa e representação numérica. Uma HMAC calculada sobre os valores normalizados não prova que os bytes recebidos eram canônicos.
- O contrato F1-292 fornece JSON Schema para o v3; um Schema não recusa necessariamente duplicatas após decodificação e tampouco prova HMAC, autoridade comercial ou autenticidade do transporte.

## Implementação

- `SyntheticCommercialEpochSignedCommand::verifyCanonicalJsonSignature` valida bytes UTF-8 JSON com tamanho de até 4096, objeto no topo e payload objeto; exige igualdade byte a byte com serialização JSON canônica (sem espaço e com campos originais na ordem). Isso recusa duplicatas e qualquer representação reserializada de forma diferente, inclusive quando a chave repetida tem o mesmo valor.
- `SyntheticCommercialEpochSignedCommand::verifyCanonicalJson` realiza a mesma validação, depois as verificações v3 já existentes (HMAC, epoch de chave/comercial, issuer/audience, campo estrito, prazo) e por último delega a reserva de nonce ao ReplayStore recebido. O backend padrão permanece `DenyingReplayStore`.
- Métodos de array legados continuam existindo para objetos previamente autenticados no laboratório, **não** para interpretação direta de corpo HTTP bruto. Nenhum endpoint foi criado.

## Testes negativos F1-293

Um comando sintético v3 válido de Onepage/Landing Page passa pela verificação de assinatura e pelo replay store efêmero somente uma vez. As mutações são negadas: wire vazio, root array/null, payload ausente/array, BOM, excesso de tamanho, whitespace, escapes Unicode, duplicação de `mac`, issuer e tenant, payload adulterado, token keyEpoch/commercialEpoch alterado, assinatura errada, ordem de campos reescrita, float em revisão contratual, downgrade v1/v2 e replay. O teste certifica que wire inválido **não toca o replay store**, para evitar consumir um nonce legítimo prematuramente.

## Não aprovado para operação

Esta proteção é validação estrita de texto assinado; ainda faltam origem real mTLS, chave/epochs de um serviço confiável, fonte independente do Portal, autorização atual por contrato/tenant, nonce durável atômico, revogação fora de restore, testes HTTPS, identidade de serviço e aprovação AppSec. Mesmo um JSON válido **não concede autoridade**. Dez gates de produção continuam BLOCKED; sem custos, publicação, merge ou deploy.
