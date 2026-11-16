<?php

namespace App\Notifications;

use App\Models\Group;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class GroupFullNotification extends Notification
{
    use Queueable;

    protected Group $group;
    protected string $alertType;

    public function __construct(
        Group $group,
        string $alertType = 'FULL_ALERT'
    ) {
        $this->group = $group;
        $this->alertType = $alertType;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $percentage = $this->group->capacity > 0
            ? round(
                ($this->group->current_count
                    / $this->group->capacity) * 100,
                2
            )
            : 0;

      
         $message = $this->alertType === 'FULL_ALERT'
         ? "Group '{$this->group->name}' has reached its maximum capacity ({$this->group->capacity})."
         : "Group '{$this->group->name}' has reached {$percentage}% of its capacity.";


        return [
            'group_id' => $this->group->id,
            'group_name' => $this->group->name,
            'alert_type' => $this->alertType,
            'percentage' => $percentage,
            'message' => $message,
        ];
    }
}
