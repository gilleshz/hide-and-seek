package fr.gshz.hideandseek.feature.creategame

import android.content.ContentResolver
import android.net.Uri
import android.provider.OpenableColumns
import fr.gshz.hideandseek.core.util.StreamLimitExceededException
import fr.gshz.hideandseek.core.util.readCapped

private const val MAX_GTFS_BYTES = 100L * 1024 * 1024

/**
 * Reads a picked GTFS zip with a 100 MB cap (the server's upload_max_filesize):
 * rejects on the provider's declared size, then bounds the actual read.
 * Throws [StreamLimitExceededException] when the file is too large; returns
 * null when it cannot be read.
 */
internal fun readGtfsFile(resolver: ContentResolver, uri: Uri): ByteArray? {
    val declared = resolver.query(uri, arrayOf(OpenableColumns.SIZE), null, null, null)?.use { cursor ->
        if (cursor.moveToFirst() && !cursor.isNull(0)) cursor.getLong(0) else null
    }
    if (declared != null && declared > MAX_GTFS_BYTES) throw StreamLimitExceededException()
    return try {
        resolver.openInputStream(uri)?.use { input -> input.readCapped(MAX_GTFS_BYTES) }
    } catch (e: StreamLimitExceededException) {
        throw e
    } catch (_: Exception) {
        null
    }
}
