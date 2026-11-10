<?php

namespace Froxlor\Ext\OneClickUpdate;

/**
 * Output helpers for HTML that Froxlor prints unescaped.
 */
final class Html
{
	/**
	 * A message for Froxlor's error page (Response::dynamicError).
	 *
	 * Froxlor prints the message with |raw and outside any v-pre, and Vue
	 * compiles the whole page. Messages can contain text from outside (error
	 * texts of Froxlor or of the version server). The text is therefore
	 * HTML-escaped, and wrapped in v-pre so Vue does not evaluate {{ … }}; as
	 * a second line of defence the braces themselves are encoded too.
	 */
	public static function errorMessage(string $message): string
	{
		$escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$escaped = str_replace(['{', '}'], ['&#123;', '&#125;'], $escaped);

		return '<span v-pre>' . $escaped . '</span>';
	}
}
