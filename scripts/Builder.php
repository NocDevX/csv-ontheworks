<?php

class Builder
{
    private $separator;
    private $fromEncoding;
    private $toEncoding;
    private $file = [];
    private $sql;
    private $tableName;
    private $schema;
    private $headers;

    public function __construct()
    {
        $this->separator = !empty($_POST['separator']) ? $_POST['separator'] : ',';
        $this->fromEncoding = !empty($_POST['sourceEncoding']) ? $_POST['sourceEncoding'] : false;
        $this->toEncoding = !empty($_POST['targetEncoding']) ? $_POST['targetEncoding'] : false;
        $this->schema = !empty($_POST['targetSchema']) ? $_POST['targetSchema'] : 'migracao_automatica';
    }

    /**
     * @param $file array
     * @return void
     */
    public function setFile($file)
    {
        $this->file = $file;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function build()
    {
        $file = fopen($this->file['tmp_name'], 'r');
        if ($file === false) {
            header("HTTP/1.1 500 Internal Server Error");
        }

        $this->createTable($file);
        $this->importData($file);

        fclose($file);
    }

    /**
     * @param $file
     * @return void
     */
    private function createTable($file)
    {
        $this->tableName = $this->getFileName();
        $fileHeaders = $this->buildHeaders($file);

        $createTableSql = "
            DROP TABLE IF EXISTS {$this->schema}.{$this->tableName};
            CREATE TABLE {$this->schema}.{$this->tableName} ($fileHeaders);
        ";

        $this->sql = preg_replace("/\n$|\s{2,}/", '', $createTableSql);
    }

    public function getFileName()
    {
        $tableName = mb_strtolower(preg_replace('/\s|\./', '_', $this->file['name']));
        $tableName = preg_replace('/_+/', '_', $tableName);
        return preg_replace('/_csv/', '', $tableName);
    }

    private function buildHeaders($file)
    {
        $characterConversion = [
            '�' => 'A', '�' => 'A', '�' => 'A', '�' => 'A', '�' => 'A',
            '�' => 'a', '�' => 'a', '�' => 'a', '�' => 'a', '�' => 'a',
            '�' => 'E', '�' => 'E', '�' => 'E', '�' => 'E',
            '�' => 'e', '�' => 'e', '�' => 'e', '�' => 'e',
            '�' => 'I', '�' => 'I', '�' => 'I', '�' => 'I',
            '�' => 'i', '�' => 'i', '�' => 'i', '�' => 'i',
            '�' => 'O', '�' => 'O', '�' => 'O', '�' => 'O', '�' => 'O',
            '�' => 'o', '�' => 'o', '�' => 'o', '�' => 'o', '�' => 'o',
            '�' => 'U', '�' => 'U', '�' => 'U', '�' => 'U',
            '�' => 'u', '�' => 'u', '�' => 'u', '�' => 'u',
            '�' => 'C', '�' => 'c'
        ];

        $headers = fgetcsv($file, 0, $this->separator, '�');
        $headers = array_map(function ($header) use (&$characterConversion) {
            list($fromEncoding, $toEncoding) = $this->getStringEncodings($header);

            $header = strtr($header, $characterConversion);
            $header = mb_convert_encoding($header, $fromEncoding, $toEncoding);
            $header = preg_replace('/\"|\s+$|\n$|\.|\(|\)/', '', $header);
            $header = preg_replace("/\s|\/|-/", '_', $header);
            $header = preg_replace("/_+/", '_', $header);
            $header = mb_strtolower($header);

            return substr($header, 0, 62) . " TEXT";
        }, $headers);

        $this->headers = implode(', ', $headers);
        return $this->headers;
    }

    /**
     * @param $string
     * @return array
     */
    private function getStringEncodings($string)
    {
        $fromEncoding = $this->fromEncoding ?: mb_detect_encoding($string);
        $fromEncoding = $fromEncoding ?: 'UTF-8';
        $toEncoding = $this->toEncoding ?: 'UTF-8';

        $encodings = [];
        $encodings[] = $fromEncoding;
        $encodings[] = $toEncoding;

        return $encodings;
    }

    public function importData($file)
    {
        $migrationSchema = $this->schema ?: "migracao_automatica";

        $conn = "-h localhost -p 5432 -d import_csv -U postgres";
        pg_connect("host=localhost port=5432 dbname=import_csv user=postgres");
        $sqlSchema = "CREATE SCHEMA IF NOT EXISTS {$migrationSchema}; ";

        pg_query($sqlSchema);
        $result = pg_query($this->sql);

        if ($result === false) {
            throw new Exception("Houve um problema ao importar para a tabela {$this->tableName}");
        }

        $filePath = stream_get_meta_data($file)['uri'];

        $headers = str_replace('TEXT', '', $this->headers);
        $sqlCsvImport = "\"\COPY {$migrationSchema}.{$this->tableName} ({$headers}) ";
        $sqlCsvImport .= "FROM '{$filePath}' (DELIMITER '|', QUOTE '�', FORMAT 'csv', HEADER)\"";

        $command = "psql {$conn} -c {$sqlCsvImport} 2>&1";
        exec($command, $execResult, $execCode);

        if ($execCode > 0) {
            $mensagemErro = json_encode($execResult);
            if ($mensagemErro) {
                throw new \Exception("$mensagemErro");
            }

            throw new Exception("Houve um problema ao importar para a tabela {$this->tableName}");
        }
    }
}