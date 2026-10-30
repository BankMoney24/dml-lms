-- Update 41 (ADR 0041): what the school calls the people who learn there.
--
-- tenants.learner_term: learner (the default, also when NULL), student, pupil or
--   trainee. Pages say the school's word wherever the platform's own text says
--   "learner"; what teachers and learners write themselves is left as written.
ALTER TABLE tenants ADD COLUMN learner_term VARCHAR(20) NULL AFTER group_term;
ALTER TABLE tenants ADD CONSTRAINT ck_tenants_learner_term CHECK (learner_term IS NULL OR learner_term IN ('learner', 'student', 'pupil', 'trainee'));
