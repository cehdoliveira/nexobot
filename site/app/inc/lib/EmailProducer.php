<?php
/**
 * EmailProducer.php
 * 
 * Produtor Kafka para envio assíncrono de emails
 * Envia mensagens para o tópico Kafka que serão processadas pelo worker
 * 
 * @package Nexo
 * @author Nexo Framework
 * @version 1.0
 */

class EmailProducer
{
    /**
     * Instância singleton
     */
    private static ?EmailProducer $instance = null;
    
    /**
     * Producer RdKafka
     */
    private ?\RdKafka\Producer $producer = null;
    
    /**
     * Tópico Kafka
     */
    private ?\RdKafka\ProducerTopic $topic = null;
    
    /**
     * Configurações do Kafka
     */
    private array $config = [];
    
    /**
     * Construtor privado (Singleton)
     */
    private function __construct()
    {
        try {
            $this->config = [
                'host' => defined('KAFKA_HOST') ? KAFKA_HOST : 'kafka_nexobot',
                'port' => defined('KAFKA_PORT') ? KAFKA_PORT : '9092',
                'topic' => defined('KAFKA_TOPIC_EMAIL') ? KAFKA_TOPIC_EMAIL : 'emails',
            ];
            
            // Configurar producer
            $conf = new \RdKafka\Conf();
            $conf->set('metadata.broker.list', $this->config['host'] . ':' . $this->config['port']);
            
            // Timeout de produção
            $conf->set('socket.timeout.ms', '50');
            $conf->set('queue.buffering.max.ms', '100');
            
            // Criar producer
            $this->producer = new \RdKafka\Producer($conf);
            
            // Criar tópico
            $this->topic = $this->producer->newTopic($this->config['topic']);
            
        } catch (Exception $e) {
            error_log("EmailProducer Error: " . $e->getMessage());
        }
    }
    
    /**
     * Obtém instância singleton
     */
    public static function getInstance(): EmailProducer
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Envia email para fila Kafka
     * 
     * @param string|array $to Destinatário(s)
     * @param string $subject Assunto
     * @param string $body Corpo do email (HTML ou texto)
     * @param array $options Opções adicionais (cc, bcc, attachments, isHtml, replyTo)
     * @return bool Sucesso do envio para fila
     */
    public function sendEmail($to, string $subject, string $body, array $options = []): bool
    {
        try {
            if (!$this->producer || !$this->topic) {
                throw new Exception("Kafka producer não inicializado");
            }
            
            // Preparar dados do email
            $emailData = [
                'to' => is_array($to) ? $to : [$to],
                'subject' => $subject,
                'body' => $body,
                'cc' => $options['cc'] ?? [],
                'bcc' => $options['bcc'] ?? [],
                'attachments' => $options['attachments'] ?? [],
                'isHtml' => $options['isHtml'] ?? true,
                'replyTo' => $options['replyTo'] ?? null,
                'timestamp' => time(),
                'priority' => $options['priority'] ?? 'normal', // high, normal, low
            ];
            
            // Serializar dados
            $message = json_encode($emailData);
            
            // Enviar para Kafka
            $this->topic->produce(RD_KAFKA_PARTITION_UA, 0, $message);
            
            // Flush assíncrono
            $this->producer->poll(0);
            
            // Flush para garantir envio (com timeout)
            for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
                $result = $this->producer->flush(100);
                if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                    return true;
                }
            }
            
            throw new Exception("Falha ao enviar mensagem para Kafka após flush");
            
        } catch (Exception $e) {
            error_log("EmailProducer::sendEmail Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Envia email simples (atalho)
     * 
     * @param string $to Destinatário
     * @param string $subject Assunto
     * @param string $body Corpo do email
     * @return bool
     */
    public function send(string $to, string $subject, string $body): bool
    {
        return $this->sendEmail($to, $subject, $body);
    }
    
    /**
     * Envia email com template
     * 
     * @param string $to Destinatário
     * @param string $subject Assunto
     * @param string $template Nome do template
     * @param array $data Dados para o template
     * @return bool
     */
    public function sendTemplate(string $to, string $subject, string $template, array $data = []): bool
    {
        // Carregar template (pode ser implementado conforme necessidade)
        $templatePath = cAppRoot . "/ui/email/{$template}.php";
        
        if (!file_exists($templatePath)) {
            error_log("Template de email não encontrado: {$template}");
            return false;
        }
        
        // Renderizar template
        ob_start();
        extract($data);
        include $templatePath;
        $body = ob_get_clean();
        
        return $this->sendEmail($to, $subject, $body, ['isHtml' => true]);
    }
    
    /**
     * Envia email com anexos
     * 
     * @param string $to Destinatário
     * @param string $subject Assunto
     * @param string $body Corpo
     * @param array $attachments Array de caminhos de arquivos
     * @return bool
     */
    public function sendWithAttachments(string $to, string $subject, string $body, array $attachments): bool
    {
        return $this->sendEmail($to, $subject, $body, [
            'attachments' => $attachments,
            'isHtml' => true
        ]);
    }
    
    /**
     * Obtém estatísticas do producer
     */
    public function getStats(): array
    {
        if (!$this->producer) {
            return [];
        }
        
        return [
            'broker' => $this->config['host'] . ':' . $this->config['port'],
            'topic' => $this->config['topic'],
            'connected' => $this->producer !== null,
        ];
    }
    
    /**
     * Destrutor
     */
    public function __destruct()
    {
        if ($this->producer) {
            // Flush final antes de destruir
            $this->producer->flush(1000);
        }
    }
}
