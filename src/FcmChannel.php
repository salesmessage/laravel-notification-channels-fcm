<?php

namespace NotificationChannels\Fcm;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Message;
use NotificationChannels\Fcm\Exceptions\CouldNotSendNotification;
use Psr\Log\LoggerInterface;
use ReflectionException;
use Throwable;

class FcmChannel
{
    public function __construct(
        protected Dispatcher $events,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @var string|null
     */
    protected $fcmProject = null;

    /**
     * Send the given notification.
     *
     * @param mixed $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     * @return array
     *
     * @throws \NotificationChannels\Fcm\Exceptions\CouldNotSendNotification
     * @throws \Kreait\Firebase\Exception\FirebaseException
     */
    public function send($notifiable, Notification $notification)
    {
        $tokens = Arr::wrap($notifiable->routeNotificationFor('fcm', $notification));

        if (empty($tokens)) {
            return [];
        }

        // Get the message from the notification class
        $fcmMessage = $notification->toFcm($notifiable);

        if (!$fcmMessage instanceof Message) {
            throw CouldNotSendNotification::invalidMessage();
        }

        $this->fcmProject = null;
        if (method_exists($notification, 'fcmProject')) {
            $this->fcmProject = $notification->fcmProject($notifiable, $fcmMessage);
        }

        $responses = [];
        $notifiableId = is_a($notifiable, \ArrayAccess::class) ? ($notifiable['id'] ?? null) : null;

        $this->logger->info('FcmChannel.Notification.Sending', [
            'notifiable' => $notifiableId,
        ]);

        $errors = [];
        foreach ($tokens as $token) {
            try {
                $result = $this->sendToFcm($fcmMessage, $token);
                $this->logger->info('FcmChannel.Notification.Sent', [
                    'notifiable' => $notifiableId,
                    'push_token' => substr($token, 0, 10) . '...',
                    'result' => $result,
                ]);
                $responses[] = $result;
            } catch (MessagingException $exception) {
                $this->logger->warning('FcmChannel.Notification.MessagingError', [
                    'notifiable' => $notifiableId,
                    'error' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                ]);

                $this->failedNotification($notifiable, $notification, $exception, $tokens);
                $errors[] = $exception->getMessage();

            } catch (\Throwable $exception) {
                $this->logger->error('FcmChannel.Notification.Error', [
                    'notifiable' => $notifiableId,
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                    'code' => $exception->getCode(),
                ]);

                throw $exception;
            }
        }
        if ($errors) {
            throw CouldNotSendNotification::serviceRespondedWithAnError((new ServerError(implode(' | ', $errors))));
        }

        return $responses;
    }

    /**
     * @return \Kreait\Firebase\Messaging
     */
    protected function messaging()
    {
        try {
            $messaging = app('firebase.manager')->project($this->fcmProject)->messaging();
        } catch (BindingResolutionException $e) {
            $messaging = app('firebase.messaging');
        } catch (ReflectionException $e) {
            $messaging = app('firebase.messaging');
        }

        return $messaging;
    }

    /**
     * @param \Kreait\Firebase\Messaging\Message $fcmMessage
     * @param $token
     * @return array
     *
     * @throws \Kreait\Firebase\Exception\MessagingException
     * @throws \Kreait\Firebase\Exception\FirebaseException
     */
    protected function sendToFcm(Message $fcmMessage, $token)
    {
        if ($fcmMessage instanceof CloudMessage) {
            $fcmMessage = $fcmMessage->withChangedTarget('token', $token);
        }

        if ($fcmMessage instanceof FcmMessage) {
            $fcmMessage->setToken($token);
        }

        return $this->messaging()->send($fcmMessage);
    }

    /**
     * @param $fcmMessage
     * @param array $tokens
     * @return \Kreait\Firebase\Messaging\MulticastSendReport
     *
     * @throws \Kreait\Firebase\Exception\MessagingException
     * @throws \Kreait\Firebase\Exception\FirebaseException
     */
    protected function sendToFcmMulticast($fcmMessage, array $tokens)
    {
        return $this->messaging()->sendMulticast($fcmMessage, $tokens);
    }

    /**
     * Dispatch failed event.
     *
     * @param mixed $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     * @param \Throwable $exception
     * @param string|array $token
     * @return array|null
     */
    protected function failedNotification($notifiable, Notification $notification, Throwable $exception, $token)
    {
        return $this->events->dispatch(new NotificationFailed(
            $notifiable,
            $notification,
            self::class,
            [
                'message' => $exception->getMessage(),
                'exception' => $exception,
                'token' => $token,
            ]
        ));
    }
}
