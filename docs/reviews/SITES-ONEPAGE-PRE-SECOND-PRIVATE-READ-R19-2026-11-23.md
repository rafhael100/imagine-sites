# OnePage R19 — revogação antes da segunda leitura privada (somente laboratório)

## Defeito observado na base imutável

Sites #622 `6aaf97320588a17bafebae16986442d7fa30134d` estava draft com quatro workflows `success` na SHA; Portal #1097 (`b7a4510f544010f72e83ca27e0c7dafa2c388b27`) também estava verde. A função `OnepageBuyerPrivatePreviewCandidate::render()` consultava `readExact()` pela **segunda vez antes de revalidar** os recibos de confirmação do comprador, revisão editorial e head corrente. Logo, uma revogação observada depois da primeira leitura privada ainda causava uma segunda leitura sensível indevida, mesmo que a resposta final fosse negada.

O novo teste `tests/onepage-private-recheck-r19.php` usa o mesmo pedido sintético aprovado dos laboratórios anteriores, sem fabricar autoridade. A CI **vermelha test-first** na SHA `e52f97434a4d750d97cc3901ff0fe0e4b28bfd09` falhou em PHP 8.3 e 8.4 no teste `review-revoked: no second private read after current head/proofs changed`: [run 45123038762](https://github.com/rafhael100/imagine-sites/actions/runs/45123038762), jobs 137520970763 e 137520971087. Os testes anteriores R16 (20 verificações) e R18 (6 verificações), bem como a fixture base (51), tinham passado **antes** dessa falha, mostrando que era uma lacuna nova, não uma quebra das regressões herdadas.

## Alteração mínima e prova independente

Antes da segunda chamada a `readExact()`, R19 agora verifica novamente o head corrente, a confirmação dos direitos pelo mesmo comprador e a aprovação editorial por outro papel, comparando exatamente as referências/revisões/digests e os tipos, sem normalizar bytes. Uma revogação, troca de confirmação ou mudança da revisão depois da primeira leitura produz negativa **com apenas uma leitura privada**. Depois da segunda leitura ainda ocorre a verificação final; revogação **durante** a segunda leitura continua negando antes de retornar HTML.

As regressões afetadas R16/R18 foram ajustadas de dois para três checks de testemunhas e head no caminho positivo; continuam exigindo só duas leituras de bytes privados. O teste R19 cobre revogação do revisor, confirmação do comprador, head obsoleto, revogação durante segundo acesso e HTML/SHA idênticos em caso positivo. Toda negativa é uniforme, sem bytes privados/stack trace na resposta. Não foi introduzido parser, renderizador, política de pagamento, privilégio novo, gateway, contrato de IA ou alteração visual.

## Limites e dependências operacionais

Esta é uma mitigação observacional em portas injetadas e fixtures sintéticas, **não** uma garantia transacional/CAS, login corporativo ou autoridade sobre a compra. A origem oficial Laravel/PostgreSQL de revisão imutável/bytes e os recibos autenticados de comprador/revisor continuam pendentes no Portal #976; Portal #1003 e Sites #552 são precedentes de troca, não fonte de autoridade. A prévia do laboratório não é aceite final do comprador nem publicação, e o domínio permanece exigível somente nesta última etapa.

Nenhuma chamada OpenAI ocorreu; não há nesta sessão atestação conjunta de worker OnePage próprio, identidade, rota privada, modelo, saída autorizada e credencial provisionada especificamente. AI Agents #490 continua teste simulado. Landing Page/CRM/VEN são frentes separadas, sem transferência de evidência.

Nenhuma modificação de main, merge, produção, VPS, checkout/Asaas, DNS/Cloudflare, credenciais, publicação, rollback automático ou fornecedor. **Sites #38 permanece NO-GO**. Próxima prova material: vincular o leitor oficial de briefing versionado e direitos/approvação autenticados do Portal ao mesmo pedido, com CAS/epoch antes da prévia privada operacional.
