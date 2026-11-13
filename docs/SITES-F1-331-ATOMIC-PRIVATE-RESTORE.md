# F1-331 — restauração atômica do pacote fictício sem substituir destino

**13/11/2026 | Linux/Ubuntu 24.04 e GitHub CI somente | NO-GO produção**

## Motivação

F1-330 recuperava os dois arquivos de uma prévia ZIP verificada diretamente no diretório final novo. Apesar de usar mkdir exclusivo e nunca sobrescrever, uma falha durante a gravação poderia deixar a pasta final incompleta e visível para outros processos de laboratório.

F1-331 separa **staging privado** e **promoção atômica**:

1. Valida ZIP, manifesto, status não publicável e hashes antes de criar diretórios.
2. Cria um diretório privado de staging com nome local imprevisível e permissão 0700, sem reutilizar diretórios.
3. Escreve e faz fsync dos dois arquivos exatos, com O_EXCL, O_NOFOLLOW e permissão 0600.
4. Verifica **todos os bytes e hashes novamente no staging**.
5. Usa a primitiva de Linux renameat2 com RENAME_NOREPLACE para renomear para o diretório final fixo em uma operação atômica. Se outro processo criar o alvo antes da promoção, o kernel **recusa** a troca. Não existe fallback para os.rename, shell mv ou overwrite.
6. Faz fsync no diretório pai e nova leitura do nome final com os hashes originais.
7. Diante de falha, a pasta incompleta continua identificada como staging oculto e privada, para limpeza manual em laboratório. Ela NÃO assume o nome de uma restauração concluída.

## Validação

- ci/f331_atomic_private_rename.py contém somente o pequeno wrapper POSIX para renameat2; não faz chamadas externas.
- ci/f331_atomic_restore_selftest.py injeta falha de gravação, simula outro processo criando o destino imediatamente antes da promoção, verifica que o conteúdo alheio sobrevive, testa nomes rejeitados, promove um diretório novo e recusa segundo restore.
- .github/workflows/sites-f1-24-release-bundle.yml executa os testes adversariais, além de validar ZIP e recuperação completa com as prévias Laravel Onepage e Landing Page em PHP 8.3/8.4.

**Limitação explícita:** staging e promoção atômica são garantias técnicas de filesystem **local Linux**, não de bancos de dados, storage distribuído, máquinas remotas ou serviços Cloudflare. O wrapper falha se a plataforma não dispuser de renameat2; não tenta fallback inseguro. Controle de acesso/identidade Portal, verificação de domínio, proteção LGPD, retenção, restore de banco/outbox e abertura de sites públicos continuam pendentes; os dez gates permanecem BLOCKED.

Nenhum cliente, senha, CRM real, API, DNS, deploy, gasto ou merge é utilizado.
