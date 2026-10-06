-- A failed query is written to the panel log with its placeholders, and the
-- server's message without the values it quotes. Rows written before that
-- rule get it here. The rows stay, sent or not.
--
-- A statement used to be logged as it was sent, with the values bound to it.
-- The driver marks that form with the statement's length in square brackets
-- ("[84] SELECT ..."), which a statement logged with its placeholders never
-- begins with: only a row that begins that way loses its statement.
UPDATE `panel_logs`
SET `log_extra` = ''
WHERE `type` = 'pdo' AND `log_extra` REGEXP '^\\[[0-9]+\\] ';

-- The server quotes a value in places ("Incorrect integer value: 'x'", a
-- syntax error's "near '...'"). It goes up to the last quote, as
-- Database::query() logs the message now. A message already without it reads
-- the same after.
UPDATE `panel_logs`
SET `log_message` = REGEXP_REPLACE(`log_message`, '(?s-i)\\b(value: |near )''.*''', '\\1''?''')
WHERE `type` = 'pdo' AND `log_message` REGEXP '(?s-i)\\b(value: |near )''.*''';
