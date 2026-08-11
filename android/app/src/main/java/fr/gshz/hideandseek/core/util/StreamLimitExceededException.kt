package fr.gshz.hideandseek.core.util

import java.io.IOException

/**
 * Thrown by [copyCapped] and [readCapped] when the source stream holds more
 * than the allowed number of bytes. Extends IOException so the existing
 * network-error funnels in ViewModels handle it gracefully.
 */
class StreamLimitExceededException : IOException("Stream exceeded the size cap")
