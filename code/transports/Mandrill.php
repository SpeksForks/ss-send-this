<?php namespace Milkyway\SS\SendThis\Transports;

/**
 * Milkyway Multimedia
 * Mandrill.php
 *
 * @package milkyway-multimedia/ss-send-this
 * @author Mellisa Hankins <mell@milkywaymultimedia.com.au>
 */

use GuzzleHttp\Client;
use PHPMailer;
use ViewableData;
use Controller;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class Mandrill extends Mail
{
    protected $params = [
        'endpoint' => 'https://mandrillapp.com/api/1.0',
        'async' => true,
    ];

    public function start(PHPMailer $messenger, ViewableData $log = null)
    {
        if (isset($this->params['key'])) {
            if (!$messenger->PreSend()) {
                return false;
            }

            $userAgent = getenv('sendthis_user_agent') ?: isset($_ENV['sendthis_user_agent']) ? $_ENV['sendthis_user_agent'] : str_replace(' ',
                    '', singleton('LeftAndMain')->ApplicationName) . '~Mandrill';

            $response = $this->http()->post($this->endpoint('messages/send-raw'), [
                    'headers' => [
                        'User-Agent' => $userAgent,
                    ],
                    'json' => [
                        'key' => $this->params['key'],
                        'raw_message' => $messenger->GetSentMIMEMessage(),
                        'async' => $this->params['async'],
                    ],
                ]
            );

            return $this->handleResponse($response, $messenger, $log);
        }

        throw new Exception('Invalid API Key. Could not connect to Mandrill.');
    }

    public function handleResponse(ResponseInterface $response, $messenger = null, $log = null)
    {
        $body = $response->getBody();
        $failed = ($statusCode = $response->getStatusCode()) && ($statusCode < 200 || $statusCode > 399);
        $message = '';

        if (!$body) {
            $message = 'Empty response received from Mandrill' . "\n";
        }

        $results = $this->parse($response);

        if (empty($results)) {
            $message = 'No results received from Mandrill' . "\n";
        }

        foreach ($results as $result) {
            $messageId = isset($result['_id']) ? $result['_id'] : '';
            $email = isset($result['email']) ? $result['email'] : '';

            $status = isset($result['status']) ? $result['status'] : 'failed';

            if ($failed || !in_array($status, ['sent', 'queued', 'scheduled']) || isset($results['reject_reason'])) {
                $message = 'Problem sending via Mandrill' . "\n";
                $message .= urldecode(http_build_query($results, '', "\n"));
            }

            if ($message) {
                if ($log) {
                    $log->Success = false;
                }

                $message .= 'Status Code: ' . $response->getStatusCode() . "\n";
                $message .= 'Message: ' . $response->getReasonPhrase();
                $this->mailer->eventful()->fire(singleton('sendthis-event')->named('sendthis:failed', $this->mailer),
                    $messageId, $email,
                    $results, $results, $log);
                throw new Exception($message);
            }

            $this->mailer->eventful()->fire(singleton('sendthis-event')->named('sendthis:sent', $this->mailer),
                $messageId ? $messageId : $messenger->getLastMessageID(), $email, $results, $results, $log);
        }

        return true;
    }

    /**
     * Get a new HTTP client instance.
     *
     * @return \GuzzleHttp\Client
     */
    protected function http()
    {
        return new Client;
    }

    protected function endpoint($action = '')
    {
        return Controller::join_links($this->params['endpoint'], $action . '.json');
    }

    public function applyHeaders(array &$headers)
    {
        if (isset($headers['X-SendAt'])) {
            $this->params['sendAt'] = $headers['X-SendAt'];
            unset($headers['X-SendAt']);
        }

        if (array_key_exists('X-Async', $headers)) {
            $this->params['async'] = $headers['X-Async'];
            unset($headers['X-Async']);
        }

        if (array_key_exists('X-ReturnPathDomain', $headers)) {
            $this->params['returnPathDomain'] = $headers['X-ReturnPathDomain'];
            unset($headers['X-ReturnPathDomain']);
        }

        if (!isset($headers['X-MC-Track']) && (isset($this->params['tracking']) || isset($this->params['api_tracking']))) {
            $headers['X-MC-Track'] = 'opens,clicks_htmlonly';
        }

        if (isset($this->params['sub_account'])) {
            $headers['X-MC-Subaccount'] = $this->params['sub_account'];
        } elseif (isset($_ENV['mandrill_sub_account'])) {
            $headers['X-MC-Subaccount'] = $_ENV['mandrill_sub_account'];
        } elseif ($sub = getenv('mandrill_sub_account')) {
            $headers['X-MC-Subaccount'] = $sub;
        }
    }

    protected function parse(ResponseInterface $response)
    {
        $data = json_decode((string)$response->getBody(), true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new RuntimeException('Unable to parse response body into JSON: ' . json_last_error());
        }

        return $data === null ? array() : $data;
    }
}
