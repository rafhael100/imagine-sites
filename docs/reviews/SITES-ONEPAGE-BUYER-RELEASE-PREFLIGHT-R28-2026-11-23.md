# OnePage R28 — validar comprador e revisão antes da leitura confidencial de release

**Candidata técnica DRAFT:** filha de Sites #631 na HEAD imutável `ff43959d77bcdc78655e9c41fafa166afde13137`. Sem alterações no main/dev, VPS ou operação.

## Lacuna e correção

O observador do aceite final consultava `currentRelease` antes de verificar, com o renderizador de prévia privada já existente, o comprador do mesmo pedido, sua declaração de direitos, a revisão editorial independente e o digest dos bytes de briefing. Assim, uma pessoa de outra conta da mesma empresa, ou uma revisão revogada, causava uma consulta confidencial ao release embora a resposta final fosse negada.

O preflight R28 agora executa `OnepageBuyerPrivatePreviewCandidate::render` antes da primeira leitura de `currentRelease`, preservando validação de parâmetros antes de chamar portas. Depois verifica release, recibo e apresentação com as mesmas condições, versões, hashes e releituras de R26/R27. A mudança reduz leituras confidenciais dispensáveis; **não autentica usuários nem oferece CAS/isolamento transacional**.

## Teste sintético e escopo

A fixture é a mesma OnePage admitida nos testes R03/R26: empresa, pedido, comprador, briefing aprovado versionado, HTML renderizado, recibo de clique e apresentação. Os casos negativos exigem *zero leituras* das portas de release, recibo e apresentação para outro comprador da mesma empresa, direitos retirados, revisão revogada e alteração dos bytes do briefing. O positivo preserva as releituras independentes, `clientFinalAccepted=true`, `publicationAuthorized=false` e `canPublish=false`. O teste também exige saída HTTP vazia em ambos os caminhos.

Regressão afetada R26: o primeiro release negado deve passar de 1 leitura para 0; o controle positivo continua com 2 leituras. R27 permanece como regressão do race durante apresentação inicial.

**Continua NO-GO:** nenhuma origem oficial de briefing imutável e sua revisão, confirmação de direitos por comprador autenticado ou aprovação/revogação independente transacional está ligada ao Portal #976. Não há autorização pública, checkout, fonte de pagamento, worker OpenAI privado atestado, API chamada, login real, publicação nem entrega operacional. Sites #38 segue bloqueado; Landing Page permanece separada.

Execuções GitHub CI devem ser comprovadas por SHA e comentários técnicos na PR #632, não presumidas neste documento.
