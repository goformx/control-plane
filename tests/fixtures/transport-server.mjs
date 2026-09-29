import { createServer } from 'node:net';

const server = createServer(socket => {
  socket.once('data', request => {
    const path = request.toString('ascii').split(' ')[1];
    const header = (status, fields = '') => `HTTP/1.1 ${status}\r\n${fields}Connection: close\r\n\r\n`;
    switch (path) {
      case '/below': socket.end(header('200 OK', 'Content-Length: 3\r\n') + 'abc'); break;
      case '/exact': socket.end(header('200 OK', 'Content-Length: 4\r\n') + 'abcd'); break;
      case '/above': socket.end(header('200 OK', 'Content-Length: 5\r\n') + 'abcde'); break;
      case '/missing': socket.write(header('200 OK') + 'abc'); setTimeout(() => socket.end(), 25); break;
      case '/short': socket.end(header('200 OK', 'Content-Length: 4\r\n') + 'abc'); break;
      case '/chunked': socket.end(header('200 OK', 'Transfer-Encoding: chunked\r\n') + '4\r\nabcd\r\n0\r\n\r\n'); break;
      case '/chunked-over': socket.end(header('200 OK', 'Transfer-Encoding: chunked\r\n') + '5\r\nabcde\r\n0\r\n\r\n'); break;
      case '/timeout':
        socket.write(header('200 OK', 'Content-Length: 4\r\n') + 'a');
        setTimeout(() => socket.end('bcd'), 2000);
        break;
      case '/error': socket.end(header('404 Not Found', 'Content-Length: 2\r\n') + 'no'); break;
      case '/error-over': socket.end(header('500 Error', 'Content-Length: 5\r\n') + 'abcde'); break;
      default: socket.end(header('404 Not Found', 'Content-Length: 0\r\n'));
    }
  });
});
server.listen(0, '127.0.0.1', () => process.stdout.write(`${server.address().port}\n`));
