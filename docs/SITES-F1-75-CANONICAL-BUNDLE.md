# F1-75 — Manifesto privado com JSON estritamente canônico

O código de validação do pacote `SyntheticReleaseBundle` já exigia os campos e o SHA-256 exatos, mas usava `json_decode` e aceitava várias serializações diferentes da mesma estrutura, incluindo possíveis **chaves duplicadas**, pois o parser PHP normaliza a última ocorrência. Outro consumidor poderia interpretar a primeira ocorrência e obter significado divergente.

A correção exige **equivalência byte a byte** entre o manifesto recebido e a serialização gerada pelo builder confiável, com a sequência exata de chaves, identação, escapes, flags e quebra de linha. O hash do HTML e a correspondência a tenant, site e release continuam obrigatórios.

Testes sintéticos em PHP 8.3 e 8.4 verificam 2 produtos × 3 temas, rejeitando JSON minificado, espaço adicional, chave duplicada igual ou conflitante, releaseRef duplicada e representação Unicode alternativa do mesmo nome de campo. As versões legítimas continuam verificáveis. O pacote segue `publishable=false`, `officialClientAcceptance=false` e não implementa assinatura ou aprovação real.

Nenhum dado de cliente, banco, nuvem, URL de produção, DNS, gasto ou deployment foi modificado. Esta defesa protege o **formato de laboratório**, mas não substitui a autenticação, assinatura de release ou revisão de segurança independente exigidas pelos 10 gates operacionais.
