-- An address at a free-mail provider says nothing about a company, so no account's domain is one of them. The
-- program's list is identity::FREE_MAIL; this is that list as it stood when the migration was written.
UPDATE "user" SET domain = NULL
WHERE lower(trim(domain)) IN (
    'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'aol.com',
    'icloud.com', 'me.com', 'mac.com', 'protonmail.com', 'proton.me', 'gmx.com', 'gmx.de', 'web.de', 'mail.com', 'zoho.com',
    'yandex.com', 'qq.com', '163.com', 'comcast.net', 'verizon.net', 'att.net', 'sbcglobal.net'
);
