PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;

CREATE TABLE IF NOT EXISTS goods_nomenclature (
    code_taric TEXT NOT NULL,          -- Format 10 chiffres (ex: '0101210000')
    suffix TEXT NOT NULL DEFAULT '80', -- Suffixe TARIC (généralement '80')
    sh6 TEXT GENERATED ALWAYS AS (substr(code_taric, 1, 6)) STORED,
    chapter TEXT GENERATED ALWAYS AS (substr(code_taric, 1, 2)) STORED,
    hier_pos INTEGER NOT NULL,         -- 2, 4, 6, 8, 10
    indent INTEGER DEFAULT 0,
    desc_fr TEXT,
    desc_en TEXT,
    valid_from TEXT,
    valid_to TEXT,
    PRIMARY KEY (code_taric, suffix)
);

CREATE INDEX IF NOT EXISTS idx_taric_sh6 ON goods_nomenclature(sh6);
CREATE INDEX IF NOT EXISTS idx_taric_chapter ON goods_nomenclature(chapter);

-- Table FTS5 pour recherche ultra-rapide plein texte (FR et EN)
CREATE VIRTUAL TABLE IF NOT EXISTS goods_fts USING fts5(
    code_taric,
    desc_fr,
    desc_en,
    tokenize = 'unicode61 remove_diacritics 2'
);

-- Triggers de synchro FTS5
CREATE TRIGGER IF NOT EXISTS trg_goods_ai AFTER INSERT ON goods_nomenclature BEGIN
    INSERT INTO goods_fts(rowid, code_taric, desc_fr, desc_en) 
    VALUES (new.rowid, new.code_taric, coalesce(new.desc_fr, ''), coalesce(new.desc_en, ''));
END;

CREATE TRIGGER IF NOT EXISTS trg_goods_au AFTER UPDATE ON goods_nomenclature BEGIN
    UPDATE goods_fts SET desc_fr = coalesce(new.desc_fr, ''), desc_en = coalesce(new.desc_en, '') 
    WHERE rowid = old.rowid;
END;
