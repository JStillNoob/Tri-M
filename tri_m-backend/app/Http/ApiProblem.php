<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ApiProblem
{
    public static function respond(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();
        if ($status < 400 || ! $request->is('api/*', 'sanctum/csrf-cookie')) {
            return $response;
        }

        $message = match ($status) {
            401 => $request->is('api/login')
                ? 'Check your email and password and try again. If you still cannot sign in, ask the Owner to check your account.'
                : 'Your session has expired. Log in again to continue.',
            403 => 'You do not have permission to do this. Ask the Owner for help.',
            404 => 'We could not find what you were looking for. Go back and try opening it again.',
            405 => 'This action is unavailable. Go back and try again.',
            409 => $exception::class === HttpException::class && $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'This information has changed. Check the latest details and try again.',
            410 => 'This preview expired. Open the workbook again.',
            413 => 'This file is too large. Choose an Excel file of 2 MB or less.',
            419 => $request->is('api/login')
                ? 'We could not start your session. Reload the login page and try again.'
                : 'Your session has expired. Log in again to continue.',
            422 => 'Please check the highlighted information and try again.',
            429 => 'Too many attempts. Wait a moment and try again.',
            default => 'Something went wrong. Please try again.',
        };

        $body = ['pos_error' => true, 'message' => $message];
        if ($exception instanceof ValidationException) {
            $body['errors'] = self::validationErrors($exception, $request);
        }

        if ($response instanceof JsonResponse) {
            return $response->setData($body);
        }

        return response()->json($body, $status, array_filter(['Retry-After' => $response->headers->get('Retry-After')]));
    }

    private static function validationErrors(ValidationException $exception, Request $request): array
    {
        $errors = $exception->errors();
        $failed = $exception->validator->failed();
        $review = $request->is('api/imports/confirm-workbook') && is_string($request->input('review'))
            ? json_decode($request->input('review'), true) : null;

        foreach ($errors as $field => $messages) {
            $rule = array_key_first($failed[$field] ?? []);
            if ($rule === null) {
                continue;
            }

            $parts = explode('.', $field);
            $name = array_pop($parts);
            $label = match ($name) {
                'category_id', 'category_name' => 'category',
                'supplier', 'supplier_id' => 'supplier',
                'price', 'selling_price', 'approved_selling_price', 'reviewed_catalog_price' => 'selling price',
                'cost', 'unit_cost', 'item_cost' => 'item cost',
                'product_id' => ($parts[0] ?? null) === 'items' ? 'product in the basket' : 'product',
                'items' => 'basket items',
                'name' => match (true) {
                    $request->is('api/categories', 'api/categories/*') => 'category name',
                    $request->is('api/suppliers') => 'supplier name',
                    default => 'product name',
                },
                'new_name', 'product_name' => 'product name',
                'markup_percentage' => 'markup percentage',
                'amount_tendered' => 'cash received',
                'wallet_provider' => 'wallet provider',
                'reference_no' => 'payment reference number',
                'method', 'payment' => 'payment method',
                'purchase_date', 'date', 'from', 'to' => 'date',
                'stock' => 'starting stock',
                'sku' => 'product code',
                'header' => 'header row',
                'filter', 'sort', 'period', 'status', 'availability' => 'filter',
                'row_number', 'rows', 'decision', 'revision', 'review', 'draft_id' => 'workbook review',
                default => str_replace('_', ' ', $name),
            };

            $message = match (true) {
                in_array($name, ['idempotency_key', 'request_key'], true) => 'Reload this page and try again.',
                $name === 'file' => match ($rule) {
                    'Required' => 'Choose an Excel workbook to continue.',
                    'Max', 'Uploaded' => 'Choose an Excel workbook of 2 MB or less and try again.',
                    default => 'Choose a readable Excel workbook ending in .xlsx.',
                },
                $name === 'mapping' || in_array('mapping', $parts, true) => 'Check the workbook columns and choose one for each required field.',
                $name === 'price_decision' => 'Choose whether to keep the current price or set a new one.',
                $name === 'email' => 'Enter a valid email address.',
                $name === 'password' => 'Enter your password.',
                $name === 'quantity' => 'Enter a valid whole quantity greater than zero.',
                $name === 'markup_percentage' => 'Enter a markup percentage of zero or more with up to four decimal places.',
                $rule === 'Gt' && in_array($name, ['selling_price', 'approved_selling_price'], true) => 'Enter a selling price greater than zero.',
                in_array($rule, ['Numeric', 'Decimal'], true) => "Enter a valid {$label} with up to two decimal places.",
                $rule === 'Exists' => "That {$label} is unavailable. Choose another one.",
                $rule === 'Unique' => "That {$label} is already in use. Choose another one.",
                in_array($rule, ['Required', 'RequiredIf'], true) => "Enter or choose a {$label} to continue.",
                $rule === 'DateFormat' => 'Choose a valid date.',
                $rule === 'Max' && is_string($exception->validator->getData()[$field] ?? null)
                    && in_array($name, ['name', 'reference_no', 'supplier', 'search'], true) => "Shorten the {$label} to {$failed[$field][$rule][0]} characters or fewer.",
                default => "Check the {$label} and try again.",
            };
            $row = ($parts[0] ?? null) === 'review' && ($parts[1] ?? null) === 'rows'
                ? ($review['rows'][$parts[2] ?? -1]['row_number'] ?? null) : null;
            $errors[$field] = [is_int($row) && $row > 0 ? "Row {$row}: {$message}" : $message];
        }

        return $errors;
    }
}
