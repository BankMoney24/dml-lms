-- Update 33 (ADR 0033): informal ("everyday") learning.
--
-- courses.track 'everyday': practical courses for life and work outside a school
--   curriculum (phones, money online, selling, staying safe), for traders,
--   artisans, parents and anyone learning for themselves.
-- tenants.kind 'community': community and informal learning centres, with no
--   terms, levels or fixed intakes.

ALTER TABLE courses DROP CONSTRAINT ck_courses_track;
ALTER TABLE courses ADD CONSTRAINT ck_courses_track CHECK (track IS NULL OR track IN ('school', 'skills', 'everyday'));

ALTER TABLE tenants DROP CONSTRAINT ck_tenants_kind;
ALTER TABLE tenants ADD CONSTRAINT ck_tenants_kind CHECK (kind IS NULL OR kind IN ('school', 'university', 'polytechnic', 'training_centre', 'community', 'other'));
