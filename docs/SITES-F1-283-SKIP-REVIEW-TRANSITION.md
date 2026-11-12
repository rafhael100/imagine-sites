# F1-283 — Revisão obrigatória ao alterar o link de acessibilidade

O link “Pular para o conteúdo” da F1-282 passou a possuir uma transição editorial explícita para ativação e desativação, independente das revisões ordinárias de texto, SEO e tema.

- O comando exige estado de revisão interna concluída e aguardando o Portal, papel sintético de revisor, revisão exata e release novo.
- Recalcula SHA-256 e HTML da versão anterior; se o conteúdo divergir do workflow aprovado, nega a alteração.
- Muda somente a opção de link para o conteúdo, preservando os textos, produto, paleta, layout, CTA, FAQ e demais configurações.
- Produz um novo artefato e manifesto privado e devolve o workflow a `drafting` com `artifactDigest=null`, impedindo reaproveitar aceite antigo.
- Recusa alteração sem efeito, release repetido, papel de agente, revisão defasada e alteração do HTML original.
- Testes cobrem 12 transições em dois produtos, três estilos, ativação e desativação, mais um caso sem FAQ.

Os papéis de teste não são identidade autenticada. Nenhuma operação permite publicação, CRM, entrega a cliente, resposta a visitantes ou bypass dos dez gates operacionais `BLOCKED`.