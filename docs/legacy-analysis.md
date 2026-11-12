# Referência do projeto original

O arquivo `archive (4).zip` contém vários sites independentes. A referência pertinente à agenda é `infonl.com.br/pac/`, uma aplicação PHP procedural com MySQL, JavaScript, FullCalendar e Chart.js. Os demais sites e as dependências incluídas no arquivo não são parte desta reestruturação.

Esta análise foi feita por leitura do ZIP. Nenhum código, script SQL ou configuração do arquivo foi executado. Credenciais, dados pessoais e o histórico Git do legado não foram copiados.

## Estruturas aproveitadas como referência

| Legado | Finalidade |
| --- | --- |
| `usuarios` e `instrutores` | Identidade, perfil e vínculo com as aulas. Existiam apenas administrador e instrutor. |
| `turmas` | Código, nome, curso, cidade, período e situação. |
| `salas` | Nome, tipo, capacidade e situação. |
| `aulas_agendadas` | Turma, instrutor, sala, tipo, início, fim e observação. |
| `rastreios_instrutor` | Data, quantidade de horas e justificativa de atividades fora de sala. |
| `notificacoes_aula` | Registro de entrega ou falha dos lembretes por e-mail. |
| `cidades_pr` | Municípios de referência. |
| `feriados` | Datas exibidas na agenda. |

O legado oferece calendário, formulário de agendamento, identificação de conflitos de instrutor/sala, repetição semanal e resumos de horas. O instrutor consulta a própria agenda. O vínculo antigo tenta resolver a identidade por ID, e-mail e até nome; a nova aplicação deve usar uma chave explícita para impedir associações ambíguas.

## Diferenças exigidas pela reestruturação

- Separar gerente e coordenador, mantendo aprovação de horas com o gerente.
- Converter o registro direto de horas em solicitação com estado e decisão. Contabilizar somente solicitações aprovadas.
- Trocar o foco financeiro dos painéis por horas de aula, horas fora de sala e alertas mensais de 40/50 horas.
- Identificar as cores pelo curso. No legado, a cor correspondia ao tipo de aula.
- Usar crachá, troca obrigatória da senha inicial e acesso persistente seguro.
- Permitir ao instrutor preencher o conteúdo de uma aula própria.
- Acrescentar notificação da alteração da agenda e sincronização entre usuários. O job encontrado no legado envia lembrete antes da aula, e o endpoint de edição analisado não chama o serviço de e-mail.
- Acrescentar frota, deslocamentos e mapa da programação, reserva de recursos e consulta de turma pelo aluno. Essas estruturas não foram identificadas no módulo `pac`.
- Simplificar a navegação e privilegiar uma interface clara para celular. O legado oferece alternância de tema e várias métricas financeiras que não são necessárias ao pedido atual.

## Limites da referência

Há regras antigas para impedir aulas aos domingos, limitar períodos diários e impedir a troca do instrutor em uma aula existente. Elas não foram tratadas como novas exigências: o pedido explícito do usuário é a referência de escopo.

O SQL original inclui operações destrutivas de criação do banco. Ele não deve ser aplicado ao banco novo nem usado automaticamente para migração. A eventual migração de cadastros e aulas reais deve ser feita separadamente, por mapeamento de IDs e validação dos registros, sem importar senhas ou configurações do servidor anterior.
