# OnePage R34 — vínculo exato entre plano renderizado e release (NO-GO)

Base draft [Sites #637](https://github.com/rafhael100/imagine-sites/pull/637), HEAD `7c0524df9bbcdf71c51f8ee109ab381d1a740a3d`, 4/4 workflows SUCCESS consultados. Portal #1099, AI Agents #490 e trilha Landing examinados e preservados. Sites #541/#542 são históricos.

## Defeito confirmado
O observador de aceite validava SHA do HTML e revisão, porém descartava a referência de release do plano de render já validado pelo handoff. Um head, receipt e presentation coerentes com outra release podiam ser aceitos se o HTML fosse byte-idêntico. Também não detectava a troca de plano para uma nova operação mantendo os bytes HTML. Isso não equivale a uma autorização histórica.

[CI inicial R34](https://github.com/rafhael100/imagine-sites/actions/runs/45267861370) na HEAD `864e62602a06cf823432a89ec579d1fcaf386dfb`: FAILURE nas duas versões PHP, marcador `R34 render provenance: deny other-plan`. O cenário usa apenas o pedido/briefing sintético existente; nenhuma escrita de aceite.

## Correção
O candidato conserva internamente a release e o digest do handoff que já constrói e valida. O leitor recusa uma release divergente do plano antes de consultar o recibo privado. As observações internas já existentes comparam também o digest de handoff (inclui SHA do plano e entrada editorial). O render público conserva os oito campos originais; nenhuma nova leitura de fonte, parser, renderer ou alteração do contrato Portal/Sites. A fixture compartilhada agora usa a mesma release do plano e do head independente, em vez de duas referências diferentes.

R34 prova quatro negativas: plano de outra release, head/recibo/apresentação de outra release com HTML idêntico, nova operação sob a mesma release e troca da release no plano durante a primeira leitura do head. Exige zero leituras de recibo e apresentação, erro uniforme e nenhum output. Controle positivo e shape público preservados. CI final deve ser confirmada na SHA exata e registrada na PR.

## Limites
Este vínculo é uma comparação local de proveniência, não uma assinatura, CAS, sessão autenticada, aceite histórico do mesmo handoff ou aprovação oficial. A origem imutável e as decisões duráveis de comprador/revisor do Portal #976 permanecem sem ligação operacional demonstrada. Nenhuma API real; worker OnePage segregado ainda não atestado. Não altera Landing, checkout, VPS, DNS, credenciais, pagamento, publicação ou main. Sites #38 NO-GO.
