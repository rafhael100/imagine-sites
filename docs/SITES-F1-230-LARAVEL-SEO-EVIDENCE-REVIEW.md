# F1-230 — Evidência de revisão SEO na interface Laravel privada

A rota já existente `/_lab/sites/v1/seo-copy-review` passa a montar **duas revisões canônicas** (`default` e `custom`) por meio do mesmo `SyntheticSeoCopyFixtureV1` usado pelo exportador. O relatório F1-228 só aparece depois da conferência dos pacotes privados de quatro arquivos e da verificação de que a versão selecionada corresponde ao SHA-256 esperado.

Na página de revisão, o novo cartão informa os dois digests do HTML, o hash do registro de evidência, os campos autorizados e, em um painel expansível, o SHA-256 dos oito arquivos. **Nenhum hash é aceite ou assinatura.** A comparação só admite mudanças do título/descrição SEO e, opcionalmente, seus espelhos sociais — H1, corpo, CSS, robots/noindex e demais bytes são imutáveis entre os dois candidatos.

## Testes e escopo

A suíte existente `SyntheticSeoCopyReviewTest` verifica 48 visualizações (2 produtos × 3 estilos × 2 modos SEO × 2 modos sociais × 2 dispositivos), a mesma evidência nos dois dispositivos e nos dois modos, ausência de referências de empresa/site em claro, integridade dos digests, quatro arquivos por versão e bloqueios de acesso fora da CLI de testes.

A rota permanece com `PHP_SAPI=cli`, `APP_ENV=testing`, quatro flags sintéticas, IP loopback, `no-store`, `X-Robots-Tag: noindex`, CSP sem script/conexões/formulários, iframe sandbox, sem autenticação real, dados de clientes, endpoint externo, publicação, indexação ou leitura do filesystem da VPS.

Os gates de prontidão e a autoridade de contrato/aceite do Portal não são alterados. Nenhum merge automático, deploy, custo, DNS, CRM ou SEO Engine.
