<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\CustomerApp\CreateSupportTicketRequest;
use App\Models\SupportTicket;
use Illuminate\Support\Str;

/** A customer writing to support. */
class SupportController extends CustomerAppController
{
    public function store(CreateSupportTicketRequest $request)
    {
        $customer = $this->customer($request);

        // A booking that is someone else's, or does not exist, is "not found".
        $booking = $request->filled('booking_id') ? ($customer->reservations()->find($request->integer('booking_id')) ?? throw ProviderApiException::notFound('Booking not found.')) : null;

        $path = $name = null;
        if ($file = $request->file('attachment')) {
            // Kept privately under a name we choose; the name the customer gave is only remembered for display.
            $path = $file->storeAs("support-attachments/{$customer->uuid}", Str::uuid().'.'.$file->extension(), 'local');
            $name = Str::limit(preg_replace('/[^\p{L}\p{N}._ -]/u', '_', $file->getClientOriginalName()), 120, '');
        }

        $ticket = SupportTicket::create(['customer_id' => $customer->id, 'reservation_id' => $booking?->id, 'topic' => $request->validated('topic'),
            'message' => $request->validated('message'), 'attachment_path' => $path, 'attachment_name' => $name, 'status' => 'open']);

        return $this->created(['ticket_id' => $ticket->id, 'reference' => $ticket->uuid, 'created_at' => $ticket->created_at->utc()->format('Y-m-d\\TH:i:s\\Z')], 'We received your message');
    }
}
