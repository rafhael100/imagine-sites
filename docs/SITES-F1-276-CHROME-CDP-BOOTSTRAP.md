# F1-276 — Bootstrap conservador do Chrome no CI de contraste

O candidato de integração F1-275 (#324) apresentou uma única falha na suíte F1-59 de Chrome ao **inicializar a porta DevTools**, antes de carregar qualquer HTML ou executar testes de layout/contraste.

A execução de Chrome F1-59 foi ajustada para usar `--disable-dev-shm-usage` em runners com memória compartilhada restrita e um *retry* estrito, uma única vez, apenas quando a exceção é `TimeoutError('Local Chrome DevTools port never became complete')`. Uma segunda tentativa recria o perfil temporário completamente. Falhas na renderização, alterações dos hashes, QA semântica, contrastes, limites de viewport e demais erros permanecem fatais, sem retry.

O workflow de navegador F1-59 executa sete casos unitários da política de repetição antes de iniciar Chrome. Mesmo com runner instável, a defesa não pode mascarar erro de produto. Não há atualização de credencial, dependência paga, publicação, navegador remoto ou cliente real.

É uma correção de estabilidade de CI e não modifica HTML publicado, revisão, autorização Portal, CRM, branch `dev/main` ou os dez gates de produção `BLOCKED`.