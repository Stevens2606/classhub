-- The main API uses the default database (classhub).
-- The content microservice gets its own database so the two services never share tables.
CREATE DATABASE classhub_content;
