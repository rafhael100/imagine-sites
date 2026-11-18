# Imagine Sites — recuperação da busca acima do limite

## Problema observado

A fila busca por marca ou chamada principal da versão atual. O briefing permite chamadas de até 180 caracteres, mas a busca aceita até 100. Copiar uma chamada longa para o campo e enviar o formulário entregava apenas o texto genérico de erro422, retirando o formulário e os filtros da tela. A ausência de `maxlength` nativo é intencional: o navegador conta unidades UTF-16, enquanto o domínio usa caracteres Unicode.

A branch `fix/sites-queue-search-recovery-2026-11-18` parte da PR #505 / `c32c0668032eec78a4a9da5c9e2ba4eb90e1269e`. A correção mantém o limite da busca e recupera o formulário quando o excesso é seguro e pequeno o suficiente para ser preservado.

## Comportamento implementado

O DTO `PreviewQueueSearchInput` admite somente uma string UTF-8 unilinha entre 101 e 200 caracteres, com no máximo 800 bytes. Ele conserva os bytes originais, incluindo espaços válidos nas extremidades. Não normaliza nem trunca o termo. Controles, NUL, UTF-8 inválido, arrays, valores não textuais e excesso maior permanecem recusados pelo caminho estrito.

O controller tenta essa recuperação apenas no GET da fila, sem projeto ou ação. Primeiro remove `q` e valida todos os demais parâmetros com `PreviewNavigation` existente. Filtro, formato, cursor ou campo desconhecido inválido impedem a recuperação. O parser e `PreviewQueueQuery` permanecem inalterados: consultas válidas continuam limitadas a 100 caracteres e 400 bytes.

Para o erro recuperável, o controller compõe os dados sem chamar o leitor da fila. Não consulta a busca inválida nem executa uma busca alternativa sem termo. A renderização exige novamente acesso de leitura corrente; indisponibilidade e revogação mantêm a resposta403 existente.

A resposta422 preserva o termo escapado no campo e os filtros selecionados, com a mensagem “Use até 100 caracteres na busca. Encurte o texto e tente novamente.” O campo recebe `aria-invalid` e associação à mensagem por `aria-describedby`. Lista de projetos, aviso de fila vazia e paginação ficam ausentes durante o erro, evitando apresentar uma recusa como um resultado vazio.

O cursor válido permanece no contexto seguro da navegação, mas a correção pelo formulário e o link “Limpar busca” iniciam a primeira página, como já ocorre ao mudar uma busca válida. Status e formato continuam selecionados. O termo inválido fica somente no formulário dessa resposta sem cache: não entra em links, URLs de mutação, payload POST ou sessão.

## Verificação

**Suíte local completa aprovada: 152 testes, 1624 assertions e 27 skips explícitos.** O conjunto focado de DTO/controller/busca/navegação/comparação/middleware passou em 34 testes/676 assertions; as views nova e existentes passaram em 25 testes/287 assertions.

Os testes de controller usam um PDO exclusivamente negativo para provar duas condições: compor o erro não consulta a fila, e renderizá-lo exige a checagem de acesso. Eles não fabricam autorização SQL positiva. A view é verificada com dados sentinela de resultados para garantir que toda a lista, estado vazio e paginação realmente desapareçam no erro. Os casos incluem escaping e preservação de 200 emojis/800 bytes, sem `maxlength` nativo.

As revisões independentes do DTO/controller e do Blade não identificaram bloqueadores. A fixture de navegador está implementada e prevê **28 checks novos/340 HTTP**, preservando o baseline312. Exercita o formulário sem JavaScript, correção422→200, limpeza/contexto, fronteiras101/200/201, recusas estruturais/projeto/POST e acesso revogado. O POST negativo usa CSRF, CAS e briefing atuais; somente a busca na URL é inválida. Snapshots das cinco tabelas comprovam que nenhuma consulta ou recusa altera o estado persistido. Há três capturas previstas: erro desktop, erro mobile e busca corrigida. As revisões independentes dos seletores, jornada e posição dos hooks foram aprovadas. **28/340 e as capturas permanecem pendentes do CI.**

O workflow específico mantém draft, repositório, branch e SHA de base fixados. Executa a suíte da aplicação, o contrato PostgreSQL do workspace em PHP 8.3/8.4 e a jornada HTTPS/FPM/Nginx/Chromium em laboratório descartável. O job exige o marcador do ensaio novo e conserva falhas com `pipefail`. Essa entrega não altera o registro de releases ou o diário; a evidência desses componentes permanece vinculada à PR #505. PostgreSQL e Docker não foram executados localmente.

## Limites

Busca válida, comparação de versões, geração de briefing e revisão editorial conservam seus contratos. A recuperação não amplia a consulta SQL, altera conteúdo persistido ou concede acesso. O workspace continua `demonstration_only`; contrato, pagamento, aceite do cliente e publicação pertencem às fontes já definidas. Os dez gates `BLOCKED`, staging e serviços existentes permanecem preservados.
