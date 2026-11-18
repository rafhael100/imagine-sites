# Imagine Sites — identificação acessível da versão revisada

## Problema

Uma página pode oferecer revisão editorial de várias versões ao mesmo tempo. Cada formulário tinha IDs próprios, mas os nomes acessíveis dos seus campos e botão eram iguais: “Parecer editorial desta versão”, “Orientação editorial” e “Registrar revisão editorial”. O número da versão aparecia fora do formulário em texto forte, sem heading ou associação. Ao listar campos ou botões com tecnologia assistiva, esses nomes não identificavam a versão da ação.

A branch `fix/sites-review-accessible-names-2026-11-18` parte da PR #511, em `d95618bdce599e90ba9b03ff73e9f19c8baee7c1`, e torna esse alvo explícito na interface existente.

## Alteração delimitada

O cartão passa a ter um heading **Versão N**, com ID próprio. O formulário usa esse heading como nome acessível. O marcador “Atual” fica separado para que o nome continue sendo a versão exata.

Os rótulos visíveis passam a ser **Parecer editorial da versão N** e **Orientação editorial da versão N**. O botão também identifica a versão: **Registrar revisão editorial da versão N**. A interface mostra o mesmo texto que fornece como nome acessível; não há outro rótulo oculto contraditório.

Quando um envio não pode ser registrado, o resumo de erro identifica a versão do parecer. A seção de orientação preservada sem possibilidade de reenvio também a inclui em seu título. Os atributos de erro já existentes continuam associados exclusivamente à tentativa preservada: não são aplicados aos formulários de outras versões.

Payload, nomes e IDs técnicos dos campos, action, CSRF, digest, verificação de revisão e autorização permanecem os mesmos. A alteração não escolhe outra versão, transforma orientação antiga em nova tentativa ou habilita repetição de um parecer já registrado.

## Verificação

Os testes de apresentação existentes foram ajustados nos casos que já renderizam múltiplas versões, validação, conflito e limite numérico. O conjunto de cinco classes afetadas passou com **46 testes e 553 assertions**, usando `--do-not-cache-result`; as 18 assertions adicionais pertencem aos casos existentes. A revisão independente das três views e dos dois arquivos de testes não identificou bloqueadores.

A jornada real de revisão editorial reutiliza duas versões ainda revisáveis e seleciona a histórica pelos nomes acessíveis exatos em contexto próprio com JavaScript desativado. A resposta 422 conserva texto, decisão e digest nessa versão, marcando somente seus campos; a correção usa a mesma tentativa, CSRF e digest, e registra a aprovação histórica já prevista no cenário. Foram confirmadas **6 verificações novas/439 HTTP**, sem mudar o conjunto final de registros ou criar novos dados de setup.

O cenário 409 existente verifica a seção preservada por seu nome com a versão e mantém a ausência de um novo botão de envio. As capturas desktop/mobile mostram o formulário com os novos nomes e seu erro, com conferência de largura.

A CI dedicada executa o navegador real com HTTPS e materiais sintéticos. A matriz geral de Laravel já executa a suíte em PHP 8.3/8.4; não é criado outro job de SQL para uma alteração de apresentação que preserva o contrato de dados. As 429 verificações SQL da base #511 pertencem à evidência daquela entrega e não são apresentadas como uma nova execução da #513.

A revisão independente da jornada HTTP conferiu os seletores contra as views, o isolamento entre as duas versões, o contexto sem JavaScript, o POST nativo e o encerramento desse contexto. Node syntax e diff check passaram. O workflow teve YAML, Bash e restrições exatas de draft, repositório, branch e SHA da base conferidos. O safety guard passou em cópia de 608 fontes, com os dez gates BLOCKED.

### Evidência final da PR #513

O HEAD `a21781e1c1143392fbe37239a0d6ecb3938c0339` teve **11/11 check-runs SUCCESS** na conferência final. O [run 44303478376](https://github.com/rafhael100/imagine-sites/actions/runs/44303478376), job `134798900602`, confirmou os 439 checks HTTP e a remoção dos containers e redes descartáveis.

A [matriz Laravel 44303478143](https://github.com/rafhael100/imagine-sites/actions/runs/44303478143) concluiu em PHP 8.3/8.4 (jobs `134798900728` e `134798900274`). A suíte padrão teve **188 testes, 2070 assertions e 27 skips explícitos**. A execução com opt-in sintético teve **188 testes, 2082 assertions e 21 skips explícitos**. Esses resultados pertencem à CI; localmente foi executado o conjunto focado de apresentação.

O artifact `14426143530`, com 31 arquivos e 5336143 bytes, teve o SHA-256 `1d5c7ccfef8a0266e0fa5b9ef82d1904132b48d60075bd15d0fe158b3b478582` conferido antes da extração. As capturas `review-accessibility-desktop.png` (539×497) e `review-accessibility-mobile.png` (312×604) foram revisadas visualmente. Os nomes, erro e botão permanecem legíveis; no celular, o botão quebra a linha dentro da largura disponível.

## Fronteiras

O trabalho permanece em draft. Não há mudança de banco, principal, sessão, workflow comercial, aceite do cliente ou publicação. O workspace continua demonstrativo; os dez gates operacionais continuam BLOCKED.
